<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Um S3 em memória para o `async-aws` falar com ele sem rede (spec P2 §4, P2.2).
 *
 * Entende o mínimo que o espelho usa — `PUT`, `HEAD` e `GET` de objeto em estilo de caminho
 * (`/<bucket>/<chave>`) — e se comporta como o S3/R2 nos pontos que importam para a réplica:
 *  - recusa `x-amz-checksum-sha256` que não bate com o corpo (`400 BadDigest`), sem gravar;
 *  - `HEAD` de chave inexistente é `404` sem corpo; `GET` de chave inexistente é `404 NoSuchKey`;
 *  - devolve os metadados `x-amz-meta-*` gravados no PUT;
 *  - o corpo do `GET` sai em blocos de {@see $tamanhoDoBlocoDoGet} bytes, como uma resposta de rede.
 *
 * E registra TODA requisição: os testes provam por aqui que nenhuma exclusão sai (regra 0) e que a
 * chave que chega ao destino é, byte a byte, a do layout.
 *
 * O que ele NÃO prova: que a assinatura SigV4 está criptograficamente certa (confere só a forma do
 * cabeçalho) nem como o R2 de verdade responde — isso é a P2.4, contra um bucket real.
 */
final class S3Simulado
{
    /** @var array<string, array{corpo: string, tipo: string, metadados: array<string, string>, etag: string, gravadoEm: \DateTimeImmutable}> */
    private array $objetos = [];

    /** @var list<array{metodo: string, url: string, chave: ?string, cabecalhos: array<string, string>}> */
    private array $requisicoes = [];

    /** @var list<MockResponse> */
    private array $falhasProgramadas = [];

    /** Tamanho de cada bloco do corpo do GET: pequeno nos testes, para o download chegar em vários. */
    public int $tamanhoDoBlocoDoGet = 8192;

    public function __construct(
        public readonly string $endpoint = 'https://conta-teste.r2.cloudflarestorage.com',
        public readonly string $bucket = 'bluejus-teste',
        public readonly string $chaveDeAcesso = 'CHAVE-DE-TESTE',
    ) {
    }

    public function cliente(): MockHttpClient
    {
        return new MockHttpClient(fn (string $metodo, string $url, array $opcoes): MockResponse => $this->responder($metodo, $url, $opcoes));
    }

    /** A próxima requisição recebe esta resposta (erro de destino ou de rede), em vez da simulação. */
    public function falharAProxima(MockResponse $resposta): void
    {
        $this->falhasProgramadas[] = $resposta;
    }

    /** @return list<array{metodo: string, url: string, chave: ?string, cabecalhos: array<string, string>}> */
    public function requisicoes(): array
    {
        return $this->requisicoes;
    }

    /** @return list<string> */
    public function metodosUsados(): array
    {
        return array_values(array_unique(array_column($this->requisicoes, 'metodo')));
    }

    public function conteudoDe(string $chave): ?string
    {
        return $this->objetos[$chave]['corpo'] ?? null;
    }

    /** @return list<string> */
    public function chavesGravadas(): array
    {
        return array_map('strval', array_keys($this->objetos));
    }

    /**
     * Grava direto no "bucket", como se outro processo tivesse gravado — para testar o HEAD.
     *
     * @param array<string, string> $metadados
     */
    public function semear(string $chave, string $corpo, array $metadados = [], string $tipo = 'application/octet-stream'): void
    {
        $this->objetos[$chave] = ['corpo' => $corpo, 'tipo' => $tipo, 'metadados' => $metadados, 'etag' => '"' . md5($corpo) . '"', 'gravadoEm' => new \DateTimeImmutable('2026-10-15 12:00:00')];
    }

    /** @param array<string, mixed> $opcoes */
    private function responder(string $metodo, string $url, array $opcoes): MockResponse
    {
        $cabecalhos = self::cabecalhos($opcoes);
        $chave      = $this->chaveDaUrl($url);
        $corpo      = $metodo === 'PUT' ? self::corpo($opcoes) : '';
        $this->requisicoes[] = ['metodo' => $metodo, 'url' => $url, 'chave' => $chave, 'cabecalhos' => $cabecalhos];

        if ($this->falhasProgramadas !== []) {
            return array_shift($this->falhasProgramadas);
        }

        if (!$this->assinaturaTemAForma($cabecalhos)) {
            return self::erro(403, 'SignatureDoesNotMatch');
        }

        if ($chave === null || $chave === '') {
            return self::erro(400, 'InvalidRequest');
        }

        return match ($metodo) {
            'PUT'   => $this->put($chave, $corpo, $cabecalhos),
            'HEAD'  => $this->head($chave),
            'GET'   => $this->get($chave),
            default => self::erro(405, 'MethodNotAllowed'),
        };
    }

    /** @param array<string, string> $cabecalhos */
    private function put(string $chave, string $corpo, array $cabecalhos): MockResponse
    {
        $declarado = $cabecalhos['x-amz-checksum-sha256'] ?? null;
        if ($declarado !== null && !hash_equals(base64_encode(hash('sha256', $corpo, true)), $declarado)) {
            return self::erro(400, 'BadDigest');
        }
        if (isset($cabecalhos['content-length']) && (int) $cabecalhos['content-length'] !== \strlen($corpo)) {
            return self::erro(400, 'IncompleteBody');
        }

        $metadados = [];
        foreach ($cabecalhos as $nome => $valor) {
            if (str_starts_with($nome, 'x-amz-meta-')) {
                $metadados[substr($nome, 11)] = $valor;
            }
        }

        $this->objetos[$chave] = [
            'corpo'     => $corpo,
            'tipo'      => $cabecalhos['content-type'] ?? 'application/octet-stream',
            'metadados' => $metadados,
            'etag'      => '"' . md5($corpo) . '"',
            'gravadoEm' => new \DateTimeImmutable('2026-10-15 12:00:00'),
        ];

        return new MockResponse('', ['http_code' => 200, 'response_headers' => ['etag' => $this->objetos[$chave]['etag']]]);
    }

    private function head(string $chave): MockResponse
    {
        $objeto = $this->objetos[$chave] ?? null;
        if ($objeto === null) {
            return new MockResponse('', ['http_code' => 404]);
        }

        $cabecalhos = [
            'content-length' => (string) \strlen($objeto['corpo']),
            'content-type'   => $objeto['tipo'],
            'etag'           => $objeto['etag'],
            'last-modified'  => $objeto['gravadoEm']->format(\DATE_RFC7231),
        ];
        foreach ($objeto['metadados'] as $nome => $valor) {
            $cabecalhos['x-amz-meta-' . $nome] = $valor;
        }

        return new MockResponse('', ['http_code' => 200, 'response_headers' => $cabecalhos]);
    }

    private function get(string $chave): MockResponse
    {
        $objeto = $this->objetos[$chave] ?? null;
        if ($objeto === null) {
            return self::erro(404, 'NoSuchKey');
        }

        $cabecalhos = [
            'content-length' => (string) \strlen($objeto['corpo']),
            'content-type'   => $objeto['tipo'],
            'etag'           => $objeto['etag'],
            'last-modified'  => $objeto['gravadoEm']->format(\DATE_RFC7231),
        ];
        foreach ($objeto['metadados'] as $nome => $valor) {
            $cabecalhos['x-amz-meta-' . $nome] = $valor;
        }

        $blocos = $objeto['corpo'] === '' ? [] : str_split($objeto['corpo'], max(1, $this->tamanhoDoBlocoDoGet));

        return new MockResponse($blocos, ['http_code' => 200, 'response_headers' => $cabecalhos]);
    }

    /** A chave em bytes, decodificada da URL em estilo de caminho; null se não for deste bucket. */
    private function chaveDaUrl(string $url): ?string
    {
        $caminho = (string) parse_url($url, \PHP_URL_PATH);
        $prefixo = '/' . $this->bucket . '/';

        if (!str_starts_with($url, rtrim($this->endpoint, '/') . '/') || !str_starts_with($caminho, $prefixo)) {
            return null;
        }

        return rawurldecode(substr($caminho, \strlen($prefixo)));
    }

    /** @param array<string, string> $cabecalhos */
    private function assinaturaTemAForma(array $cabecalhos): bool
    {
        $padrao = sprintf(
            '#\AAWS4-HMAC-SHA256 Credential=%s/\d{8}/auto/s3/aws4_request, SignedHeaders=[a-z0-9;-]+, Signature=[0-9a-f]{64}\z#',
            preg_quote($this->chaveDeAcesso, '#'),
        );

        return preg_match($padrao, $cabecalhos['authorization'] ?? '') === 1
            && isset($cabecalhos['x-amz-date'], $cabecalhos['x-amz-content-sha256']);
    }

    /**
     * @param array<string, mixed> $opcoes
     *
     * @return array<string, string>
     */
    private static function cabecalhos(array $opcoes): array
    {
        $saida = [];
        foreach ((array) ($opcoes['headers'] ?? []) as $linha) {
            if (\is_string($linha) && str_contains($linha, ':')) {
                [$nome, $valor] = explode(':', $linha, 2);
                $saida[strtolower(trim($nome))] = trim($valor);
            }
        }

        return $saida;
    }

    /** @param array<string, mixed> $opcoes */
    private static function corpo(array $opcoes): string
    {
        $corpo = $opcoes['body'] ?? '';
        if (\is_string($corpo)) {
            return $corpo;
        }
        if ($corpo instanceof \Closure) {
            $saida = '';
            while ('' !== $pedaco = $corpo(16384)) {
                $saida .= $pedaco;
            }

            return $saida;
        }
        if (is_iterable($corpo)) {
            $saida = '';
            foreach ($corpo as $pedaco) {
                $saida .= $pedaco;
            }

            return $saida;
        }

        throw new \LogicException('Corpo de requisição em formato não previsto pelo simulador.');
    }

    private static function erro(int $status, string $codigo): MockResponse
    {
        $xml = sprintf('<?xml version="1.0" encoding="UTF-8"?><Error><Code>%s</Code><Message>simulado</Message></Error>', $codigo);

        return new MockResponse($xml, ['http_code' => $status, 'response_headers' => ['content-type' => 'application/xml']]);
    }
}
