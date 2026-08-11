<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\Service\ConexaoLeitura;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

/**
 * Ferramenta MCP `consultar_sql`.
 *
 * Não filtra tenant, não checa permissão e não passa por UseCase — enxerga o banco inteiro,
 * de todos os escritórios. É a ferramenta de investigação do dono, e é o mesmo alcance que o
 * `psql` que ele já usa à mão. Decisão consciente, registrada na spec.
 */
final class ConsultarSqlTool
{
    public const LIMITE_LINHAS = 500;

    public function __construct(
        private readonly ConexaoLeitura $conexao,
        private readonly LoggerInterface $mcpLogger,
    ) {}

    /**
     * Executa uma consulta SELECT no banco do JusPrime e devolve as linhas.
     *
     * Somente leitura: o usuário do banco não tem permissão de escrita. No máximo 500 linhas
     * são devolvidas; acima disso o campo `truncado` vem true e a consulta deve ser refinada
     * (com LIMIT, agregação ou filtro mais estreito).
     *
     * @param string $sql Consulta SELECT a executar
     *
     * @return array{colunas: list<string>, linhas: list<array<string, mixed>>, total: int, truncado: bool}
     */
    public function consultar(string $sql): array
    {
        $inicio = microtime(true);

        try {
            $resultado = $this->conexao->consultar($sql, self::LIMITE_LINHAS);
        } catch (\Throwable $erro) {
            $this->mcpLogger->error('consulta falhou', [
                'sql'  => $sql,
                'erro' => $erro->getMessage(),
            ]);

            // `Mcp\Exception\ToolCallException` é a única exceção que o SDK (mcp/sdk 0.7.0,
            // `CallToolHandler::handle()`) converte em erro DE FERRAMENTA (`isError: true`
            // dentro do `result`, sem derrubar a sessão). Um `\RuntimeException` comum cai no
            // catch genérico do SDK e vira erro de PROTOCOLO JSON-RPC — sem `isError` e sem o
            // texto do erro, o modelo não teria como se corrigir.
            throw new ToolCallException(
                sprintf('Falha ao executar a consulta: %s', $erro->getMessage()),
                0,
                $erro,
            );
        }

        $duracaoMs = (int) round((microtime(true) - $inicio) * 1000);

        $this->mcpLogger->info('consulta executada', [
            'sql'        => $sql,
            'linhas'     => count($resultado['linhas']),
            'truncado'   => $resultado['truncado'],
            'duracao_ms' => $duracaoMs,
        ]);

        return [
            'colunas'  => $resultado['colunas'],
            'linhas'   => $resultado['linhas'],
            'total'    => count($resultado['linhas']),
            'truncado' => $resultado['truncado'],
        ];
    }
}
