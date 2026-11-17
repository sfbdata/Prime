<?php

declare(strict_types=1);

namespace App\Conector\Mcp;

use App\Conector\Service\ValidadorDoTokenDoConector;
use Mcp\Server\Transport\ReadsBoundedBody;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * O 403 do conector suspenso no `/mcp` (decisão do Coordenador de 16/11/2026 sobre a liberação desligada): o
 * administrador desligou o conector, o Master bloqueou o escritório, a chave geral está desligada ou o aceite é de
 * versão antiga. O token é válido; o conector está suspenso, e religar volta sem reconectar.
 *
 * Quem julga é o {@see ValidadorDoTokenDoConector} (um juiz só): ele devolve o 403 com o erro
 * {@see ValidadorDoTokenDoConector::ERRO_CONECTOR_DESLIGADO}, o `AuthorizationMiddleware` do SDK para ali (antes da
 * `ScopePolicy`, do portão e do despacho) e formata o 403 com `WWW-Authenticate`. Este middleware, logo antes do de
 * autorização, troca essa resposta por um 403 SEM `WWW-Authenticate` e com um erro JSON-RPC de mensagem fixa:
 *
 * - sem desafio, porque qualquer `WWW-Authenticate` convida o app de I.A. a agir sobre o token: `invalid_token` o leva a
 *   renovar e, com a renovação recusada, a pedir reconexão; `insufficient_scope` o leva ao step-up (que é reconectar).
 *   Um 403 sem desafio é só "proibido agora": o app mostra o erro e tenta de novo depois, com o mesmo token;
 * - erro JSON-RPC e não `result` com `isError`, porque nada foi executado (o `result` diria que a ferramenta rodou), e
 *   sem o `_meta["mcp/www_authenticate"]` do ChatGPT, que também é pedido de step-up;
 * - o corpo é ASCII: a mensagem em português vai com o acento escapado em `ó`, e a descrição técnica é a mesma
 *   ASCII fixa do validador.
 *
 * Pedido sem `id` (notificação, lote ou corpo ilegível) recebe `id: null`, como o step-up. Nada muda nas outras
 * respostas: o 401 dos outros motivos e o 403 do escopo que falta passam como vieram.
 */
final class ConectorDesligadoMiddleware implements MiddlewareInterface
{
    use ReadsBoundedBody;

    /** A mensagem para a pessoa, fixa. */
    public const MENSAGEM = 'Conector desligado neste escritório.';

    /** Código JSON-RPC do conector suspenso (faixa de erros do servidor; o -32000 é o do step-up sem `id`). */
    public const CODIGO = -32001;

    public function __construct(
        private readonly ResponseFactoryInterface $respostas,
        private readonly StreamFactoryInterface $fluxos,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $resposta = $handler->handle($request);
        if (!self::conectorDesligado($resposta)) {
            return $resposta;
        }

        $corpo = [
            'jsonrpc' => '2.0',
            'id' => $this->idDoPedido($request),
            'error' => [
                'code' => self::CODIGO,
                'message' => self::MENSAGEM,
                'data' => ['description' => ValidadorDoTokenDoConector::DESCRICAO_LIBERACAO],
            ],
        ];

        return $this->respostas->createResponse(403)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->fluxos->createStream(json_encode($corpo, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)));
    }

    private static function conectorDesligado(ResponseInterface $resposta): bool
    {
        return $resposta->getStatusCode() === 403
            && str_contains($resposta->getHeaderLine('WWW-Authenticate'), 'error="' . ValidadorDoTokenDoConector::ERRO_CONECTOR_DESLIGADO . '"');
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
