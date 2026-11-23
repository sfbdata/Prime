<?php

declare(strict_types=1);

namespace App\Conector\Mcp;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Exception\ToolNotFoundException;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * O `tools/call` de uma ferramenta que a pessoa do token não vê (`docs/specs/mcp-remoto-oauth.md`, 6.9, decisão 38):
 * responde exatamente como o SDK responde a um nome que não existe (`CallToolHandler`, `Error::forInvalidParams` com a
 * mensagem do {@see ToolNotFoundException}), sem dizer que a ferramenta existe para outro perfil. Só aceita esse caso;
 * as outras chamadas seguem para o `CallToolHandler` do SDK. A ferramenta ainda confere o Master por conta própria
 * (decisão 39): este handler é a primeira porta, não a única.
 *
 * @implements RequestHandlerInterface<never>
 */
final class FerramentaEscondidaDaPessoa implements RequestHandlerInterface
{
    /** @param \Closure(string): bool $visivel recebe o nome da ferramenta */
    public function __construct(
        private readonly \Closure $visivel,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest && !($this->visivel)($request->name);
    }

    public function handle(Request $request, SessionInterface $session): Error
    {
        \assert($request instanceof CallToolRequest);

        return Error::forInvalidParams((new ToolNotFoundException($request->name))->getMessage(), $request->getId());
    }
}
