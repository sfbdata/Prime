<?php

declare(strict_types=1);

namespace App\Conector\Mcp;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * O `tools/list` do conector, no lugar do `ListToolsHandler` do SDK (o handler próprio vem antes dos do SDK,
 * `Mcp\Server\Builder`, e o primeiro que aceita responde): a mesma lista, menos as ferramentas que a pessoa do token não
 * vê (`docs/specs/mcp-remoto-oauth.md`, 6.9, decisão 38). O servidor é montado antes do portão, então a pergunta "vê?"
 * só é feita na hora da resposta, com a requisição já aberta.
 *
 * Uma página só, sem cursor: o catálogo é fechado e pequeno (6.3, item 1), e o filtro não pode deixar buraco entre
 * páginas.
 *
 * @implements RequestHandlerInterface<ListToolsResult>
 */
final class ListaDasFerramentasDaPessoa implements RequestHandlerInterface
{
    /**
     * @param list<Tool>               $definicoes na ordem do registro
     * @param \Closure(string): bool $visivel    recebe o nome da ferramenta
     */
    public function __construct(
        private readonly array $definicoes,
        private readonly \Closure $visivel,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    /** @return Response<ListToolsResult> */
    public function handle(Request $request, SessionInterface $session): Response
    {
        $visiveis = array_values(array_filter($this->definicoes, fn (Tool $tool): bool => ($this->visivel)($tool->name)));

        return new Response($request->getId(), new ListToolsResult($visiveis));
    }
}
