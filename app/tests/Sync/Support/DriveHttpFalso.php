<?php

declare(strict_types=1);

namespace App\Tests\Sync\Support;

use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Dublê da REDE do Google, para exercitar o {@see \App\Sync\Service\GoogleDriveClient} real sem sair
 * da máquina (DT-8). É um handler do Guzzle: fica no fundo da pilha, depois dos middlewares de
 * redirect, de `http_errors` e de autenticação do Google — então o histórico mostra a requisição
 * exatamente como sairia, já com o `Authorization` que o Google pôs.
 *
 * As rotas respondem por "MÉTODO host/caminho". Rota não prevista é falha do teste, nunca resposta
 * inventada. O `sink` é escrito como o MockHandler do Guzzle escreve.
 */
final class DriveHttpFalso
{
    public const HOST_TOKEN = 'oauth2.googleapis.com';
    public const HOST_API   = 'www.googleapis.com';

    /** @var list<RequestInterface> */
    public array $historico = [];

    /** @var array<string, list<string>> Destinos (`sink`) pedidos, por "MÉTODO host/caminho". */
    public array $destinos = [];

    /** @var array<string, \Closure(RequestInterface, array<string, mixed>): (ResponseInterface|\Throwable)> */
    private array $rotas = [];

    private int $tokensEmitidos = 0;

    /**
     * Resposta nova a cada chamada: um corpo já lido não serve para a requisição seguinte.
     *
     * @param array<string, string> $cabecalhos
     * @return \Closure(): ResponseInterface
     */
    public static function responder(int $status, string $corpo = '', array $cabecalhos = []): \Closure
    {
        return static fn (): ResponseInterface => new Response($status, $cabecalhos, $corpo);
    }

    /** Corpo de erro no formato que a API do Drive devolve. */
    public static function erroDoGoogle(int $status, string $reason, string $mensagem): string
    {
        return (string) json_encode([
            'error' => [
                'code'    => $status,
                'message' => $mensagem,
                'errors'  => [['message' => $mensagem, 'domain' => 'global', 'reason' => $reason]],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param \Closure(RequestInterface, array<string, mixed>): (ResponseInterface|\Throwable) $resposta
     */
    public function rota(string $metodo, string $hostECaminho, \Closure $resposta): self
    {
        $this->rotas[$metodo . ' ' . $hostECaminho] = $resposta;

        return $this;
    }

    /** O endpoint de token aceita a renovação e emite `token-1`, `token-2`, ... */
    public function tokenAceito(): self
    {
        return $this->rota('POST', self::HOST_TOKEN . '/token', fn (): ResponseInterface => new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode(['access_token' => 'token-' . (++$this->tokensEmitidos), 'expires_in' => 3599, 'token_type' => 'Bearer']),
        ));
    }

    /** O endpoint de token recusa a renovação como o Google recusa um refresh token revogado. */
    public function tokenRecusado(): self
    {
        return $this->rota('POST', self::HOST_TOKEN . '/token', self::responder(
            400,
            (string) json_encode(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']),
            ['Content-Type' => 'application/json'],
        ));
    }

    /**
     * @param \Closure(RequestInterface, array<string, mixed>): (ResponseInterface|\Throwable) $resposta
     */
    public function download(string $fileId, \Closure $resposta): self
    {
        return $this->rota('GET', self::HOST_API . '/drive/v3/files/' . $fileId, $resposta);
    }

    /** @param array<string, mixed> $opcoes */
    public function __invoke(RequestInterface $requisicao, array $opcoes): PromiseInterface
    {
        $this->historico[] = $requisicao;
        $chave = $requisicao->getMethod() . ' ' . $requisicao->getUri()->getHost() . $requisicao->getUri()->getPath();
        if (isset($opcoes['sink']) && is_string($opcoes['sink'])) {
            $this->destinos[$chave][] = $opcoes['sink'];
        }

        if (!isset($this->rotas[$chave])) {
            return Create::rejectionFor(new \LogicException('Requisição não prevista pelo teste: ' . $chave));
        }

        $resultado = ($this->rotas[$chave])($requisicao, $opcoes);
        if ($resultado instanceof \Throwable) {
            return Create::rejectionFor($resultado);
        }

        if (isset($opcoes['sink']) && is_string($opcoes['sink'])) {
            file_put_contents($opcoes['sink'], (string) $resultado->getBody());
            $resultado->getBody()->rewind();
        }

        return Create::promiseFor($resultado);
    }

    /**
     * O cliente HTTP que o GoogleDriveClient recebe pela costura: a configuração PADRÃO do Google (a
     * mesma da produção), trocando só o handler por este dublê. `$httpErrors` liga o que o Google
     * desliga, para provar que a checagem de status é do nosso código e não do Guzzle.
     */
    public function clienteHttp(?bool $httpErrors = null): Client
    {
        $config = (new GoogleClient())->getHttpClient()->getConfig();
        $config['handler'] = HandlerStack::create($this);
        if ($httpErrors !== null) {
            $config['http_errors'] = $httpErrors;
        }

        return new Client($config);
    }

    /** @return list<RequestInterface> As requisições que foram à API (tudo menos a renovação de token). */
    public function requisicoesDaApi(): array
    {
        return array_values(array_filter(
            $this->historico,
            static fn (RequestInterface $r): bool => $r->getUri()->getHost() !== self::HOST_TOKEN,
        ));
    }

    public function tokensPedidos(): int
    {
        return count(array_filter(
            $this->historico,
            static fn (RequestInterface $r): bool => $r->getUri()->getHost() === self::HOST_TOKEN,
        ));
    }
}
