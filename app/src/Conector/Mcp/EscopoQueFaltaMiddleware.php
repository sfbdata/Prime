<?php

declare(strict_types=1);

namespace App\Conector\Mcp;

use Mcp\Server\Transport\ReadsBoundedBody;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * O pedido de escopo a mais (step-up) para o ChatGPT (`docs/specs/mcp-remoto-oauth.md`, 5.1, item 3, e decisão 9 do
 * PR 5). O `AuthorizationMiddleware` do SDK já responde o 403 `insufficient_scope` com o `WWW-Authenticate` que o Claude
 * lê, mas sem corpo. O ChatGPT lê outro canal: o resultado com `_meta["mcp/www_authenticate"]`. Este middleware, logo
 * antes do de autorização, põe no 403 um corpo JSON-RPC com o mesmo `id` do pedido, `isError` e o mesmo desafio do
 * cabeçalho. Status e cabeçalho ficam como o SDK os fez.
 *
 * Pedido sem `id` válido (notificação, lote ou corpo ilegível) não pode receber `result`: no JSON-RPC 2.0 o `result`
 * exige o `id` do pedido. Ele recebe um objeto de erro com `id: null`, código e mensagem fixos, e o mesmo desafio em
 * `error.data._meta["mcp/www_authenticate"]`. O 403 e o `WWW-Authenticate` continuam iguais.
 *
 * Nada muda nas outras respostas, nem no 401 (o app de I.A. ainda nem conectou).
 */
final class EscopoQueFaltaMiddleware implements MiddlewareInterface
{
    use ReadsBoundedBody;

    public const META = 'mcp/www_authenticate';
    public const MENSAGEM = 'Esta conexão com o BlueJus só tem permissão de leitura. Para gravar, conecte de novo marcando a opção de gravação.';

    /** Código JSON-RPC do erro do pedido sem `id` (faixa de erros do servidor, -32000 a -32099). */
    public const CODIGO_SEM_ID = -32000;

    public function __construct(private readonly StreamFactoryInterface $fluxos)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $resposta = $handler->handle($request);
        if (!self::faltaEscopo($resposta)) {
            return $resposta;
        }

        $meta = [self::META => [$resposta->getHeaderLine('WWW-Authenticate')]];
        $id = $this->idDoPedido($request);
        $corpo = $id === null
            ? [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => self::CODIGO_SEM_ID, 'message' => self::MENSAGEM, 'data' => ['_meta' => $meta]],
            ]
            : [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => self::MENSAGEM]],
                    'isError' => true,
                    '_meta' => $meta,
                ],
            ];

        return $resposta
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->fluxos->createStream(json_encode($corpo, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)));
    }

    private static function faltaEscopo(ResponseInterface $resposta): bool
    {
        return $resposta->getStatusCode() === 403
            && str_contains($resposta->getHeaderLine('WWW-Authenticate'), 'error="insufficient_scope"');
    }

    /** O `id` JSON-RPC do pedido, para o app casar a resposta; nulo na notificação, no lote e no corpo ilegível. */
    private function idDoPedido(ServerRequestInterface $pedido): int|string|null
    {
        $corpo = $this->readBoundedBody($pedido->getBody(), StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES);
        $mensagem = $corpo === null ? null : json_decode($corpo, true);
        $id = \is_array($mensagem) && !array_is_list($mensagem) ? ($mensagem['id'] ?? null) : null;

        return \is_int($id) || \is_string($id) ? $id : null;
    }
}
