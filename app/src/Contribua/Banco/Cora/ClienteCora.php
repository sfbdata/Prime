<?php

declare(strict_types=1);

namespace App\Contribua\Banco\Cora;

use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\FalhaNoBanco;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Chamadas à Integração Direta da Cora (developers.cora.com.br): mTLS em TODA chamada (certificado e chave
 * em arquivos temporários 0600, apagados no fim) e `Authorization: Bearer` com o token de `POST /token`
 * (client_credentials, vale 24 h, sem refresh). O token fica guardado cifrado por 23 h; um 401 descarta o
 * token e tenta uma vez de novo.
 */
class ClienteCora
{
    public const BASE_STAGE = 'https://matls-clients.api.stage.cora.com.br';
    public const BASE_PRODUCAO = 'https://matls-clients.api.cora.com.br';
    private const VALIDADE_SEGUNDOS = 23 * 3600;
    private const TEMPO_SEGUNDOS = 15;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly CofreDeCertificado $cofre,
    ) {
    }

    /** Pede um token novo (Testar conexão). */
    public function token(CredenciaisCora $c): string
    {
        return $this->comArquivos($c, fn (array $mtls): string => $this->pedirToken($c, $mtls));
    }

    /**
     * @param array<string, mixed> $opcoes
     *
     * @return array<string, mixed>
     */
    public function pedir(CredenciaisCora $c, string $metodo, string $caminho, array $opcoes = []): array
    {
        return $this->comArquivos($c, function (array $mtls) use ($c, $metodo, $caminho, $opcoes): array {
            [$status, $corpo] = $this->chamar($c, $mtls, $metodo, $caminho, $opcoes, $this->tokenGuardado($c, $mtls));
            if (401 === $status) {
                $this->cache->deleteItem($c->chaveDoToken());
                [$status, $corpo] = $this->chamar($c, $mtls, $metodo, $caminho, $opcoes, $this->tokenGuardado($c, $mtls));
            }
            if ($status >= 400) {
                throw new FalhaNoBanco($this->motivo($status, $corpo));
            }

            return $corpo;
        });
    }

    /**
     * @param array{local_cert: string, local_pk: string} $mtls
     * @param array<string, mixed>                        $opcoes
     *
     * @return array{int, array<string, mixed>}
     */
    private function chamar(CredenciaisCora $c, array $mtls, string $metodo, string $caminho, array $opcoes, string $token): array
    {
        try {
            $r = $this->http->request($metodo, $c->base() . $caminho, $mtls + $opcoes + ['auth_bearer' => $token, 'timeout' => self::TEMPO_SEGUNDOS]);

            return [$r->getStatusCode(), $r->toArray(false)];
        } catch (ExceptionInterface $e) {
            throw new FalhaNoBanco('Sem resposta da Cora: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @param array{local_cert: string, local_pk: string} $mtls */
    private function tokenGuardado(CredenciaisCora $c, array $mtls): string
    {
        $item = $this->cache->getItem($c->chaveDoToken());
        if ($item->isHit() && is_string($item->get())) {
            return $this->cofre->decifrar($item->get());
        }
        $token = $this->pedirToken($c, $mtls);
        $this->cache->save($item->set($this->cofre->cifrar($token))->expiresAfter(self::VALIDADE_SEGUNDOS));

        return $token;
    }

    /** @param array{local_cert: string, local_pk: string} $mtls */
    private function pedirToken(CredenciaisCora $c, array $mtls): string
    {
        try {
            $r = $this->http->request('POST', $c->base() . '/token', $mtls + [
                'body' => ['grant_type' => 'client_credentials', 'client_id' => $c->clientId],
                'timeout' => self::TEMPO_SEGUNDOS,
            ]);
            $status = $r->getStatusCode();
            $corpo = $r->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new FalhaNoBanco('Sem resposta da Cora: ' . $e->getMessage(), 0, $e);
        }
        if (200 !== $status || !is_string($corpo['access_token'] ?? null)) {
            throw new FalhaNoBanco($this->motivo($status, $corpo));
        }

        return $corpo['access_token'];
    }

    /**
     * Certificado e chave em arquivos temporários só durante a chamada (o HttpClient pede caminhos).
     *
     * @template T
     *
     * @param callable(array{local_cert: string, local_pk: string}): T $acao
     *
     * @return T
     */
    private function comArquivos(CredenciaisCora $c, callable $acao): mixed
    {
        $certificado = $this->arquivoPrivado($c->certificadoPem);
        $chave = $this->arquivoPrivado($c->chavePem);
        try {
            return $acao(['local_cert' => $certificado, 'local_pk' => $chave]);
        } finally {
            @unlink($certificado);
            @unlink($chave);
        }
    }

    private function arquivoPrivado(string $conteudo): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'bj-cora-');
        if (false === $caminho) {
            throw new FalhaNoBanco('Não foi possível preparar o certificado.');
        }
        chmod($caminho, 0600);
        file_put_contents($caminho, $conteudo);

        return $caminho;
    }

    /** @param array<string, mixed> $corpo */
    private function motivo(int $status, array $corpo): string
    {
        $erro = $corpo['errors'][0]['message'] ?? $corpo['message'] ?? $corpo['error_description'] ?? $corpo['error'] ?? null;

        return 'A Cora respondeu ' . $status . (is_string($erro) ? ': ' . mb_substr($erro, 0, 150) : '') . '.';
    }
}
