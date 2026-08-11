<?php

declare(strict_types=1);

namespace App\Mcp\Command;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\ToolCallException;

/**
 * Traduz qualquer falha de execução de ferramenta para `ToolCallException` — a única exceção
 * que `Mcp\Server\Handler\Request\CallToolHandler` (mcp/sdk 0.7.0) reconhece como erro "seguro"
 * de ferramenta: vira `isError: true` dentro do `result` da resposta, sem derrubar a sessão.
 *
 * Sem este adaptador, o `\RuntimeException` simples que `ConsultarSqlTool`/`DescreverEsquemaTool`
 * lançam (Tasks 3 e 4 — corpo fora do escopo desta tarefa, não alterado) cai no catch genérico
 * de `CallToolHandler` e vira erro de PROTOCOLO JSON-RPC (nível de transporte, `Error::
 * forInternalError`), não `isError` dentro do resultado — o cliente perde o texto do erro e o
 * modelo não tem como se corrigir. Verificado batendo direto no processo real do comando.
 *
 * Registrado via `Server::builder()->setReferenceHandler()` em `McpServerCommand`.
 */
final class ExcecaoDeFerramentaViraErroSeguro implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ReferenceHandlerInterface $interno,
    ) {
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        try {
            return $this->interno->handle($reference, $arguments);
        } catch (ToolCallException $erro) {
            throw $erro;
        } catch (\Throwable $erro) {
            throw new ToolCallException($erro->getMessage(), 0, $erro);
        }
    }
}
