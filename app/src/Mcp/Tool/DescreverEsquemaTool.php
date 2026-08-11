<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\Service\ConexaoLeitura;
use Mcp\Exception\ToolCallException;

/**
 * Ferramenta MCP `descrever_esquema`.
 *
 * Não é conveniência: sem ela, quem escreve o SQL chuta nome de coluna. Já custou medição
 * errada neste projeto — uma consulta que roda contra coluna inventada não dá erro óbvio,
 * dá número errado.
 */
final class DescreverEsquemaTool
{
    private const LIMITE = 2000;

    public function __construct(
        private readonly ConexaoLeitura $conexao,
    ) {}

    /**
     * Descreve o esquema do banco do JusPrime.
     *
     * Sem argumento, lista todas as tabelas. Com o nome de uma tabela, devolve suas colunas
     * (tipo, se aceita nulo, valor padrão), suas chaves (primária, estrangeiras e únicas — as
     * estrangeiras dizem para qual tabela e coluna apontam, use-as para montar o JOIN certo) e
     * seus índices. Use SEMPRE antes de escrever uma consulta contra uma tabela que você não
     * conhece.
     *
     * @param string|null $tabela Nome da tabela a descrever; omita para listar todas
     *
     * @return array<string, mixed>
     */
    public function descrever(?string $tabela = null): array
    {
        try {
            if ($tabela === null || trim($tabela) === '') {
                return ['tabelas' => $this->listarTabelas()];
            }

            $tabela = trim($tabela);

            if (!in_array($tabela, $this->listarTabelas(), true)) {
                throw new ToolCallException(sprintf(
                    'A tabela "%s" não existe no schema public. Rode descrever_esquema sem '
                    . 'argumento para ver a lista.',
                    $tabela,
                ));
            }

            return [
                'tabela'  => $tabela,
                'colunas' => $this->colunas($tabela),
                'chaves'  => $this->chaves($tabela),
                'indices' => $this->indices($tabela),
            ];
        } catch (ToolCallException $erro) {
            throw $erro;
        } catch (\Throwable $erro) {
            // `listarTabelas()`/`colunas()`/`indices()` chamam `ConexaoLeitura::consultar()`
            // sem tratar falha própria — uma queda de conexão, por exemplo, escaparia como
            // `Doctrine\DBAL\Exception` crua. Só `ToolCallException` (ver `ConsultarSqlTool`
            // para o motivo) vira erro seguro de ferramenta no SDK; por isso o catch aqui
            // cobre os três caminhos de uma vez, em vez de repetir try/catch em cada método.
            throw new ToolCallException(
                sprintf('Falha ao descrever o esquema: %s', $erro->getMessage()),
                0,
                $erro,
            );
        }
    }

    /** @return list<string> */
    private function listarTabelas(): array
    {
        $resultado = $this->conexao->consultar(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
             ORDER BY table_name",
            self::LIMITE,
        );

        return array_map(
            static fn (array $linha): string => (string) $linha['table_name'],
            $resultado['linhas'],
        );
    }

    /** @return list<array<string, mixed>> */
    private function colunas(string $tabela): array
    {
        $resultado = $this->conexao->consultar(
            "SELECT column_name AS coluna,
                    data_type AS tipo,
                    CASE is_nullable WHEN 'YES' THEN 'SIM' ELSE 'NAO' END AS aceita_nulo,
                    column_default AS padrao
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = :tabela
             ORDER BY ordinal_position",
            self::LIMITE,
            ['tabela' => $tabela],
        );

        return $resultado['linhas'];
    }

    /**
     * Chaves primária, estrangeiras e únicas.
     *
     * `information_schema.columns` + `pg_indexes` (o que a ferramenta trazia) deixava a FK
     * INVISÍVEL: PK e UNIQUE ainda dava para inferir do `indexdef`, mas chave estrangeira não
     * cria índice no PostgreSQL e não aparecia em lugar nenhum. Sem ela o modelo chuta o JOIN —
     * o mesmo tipo de erro silencioso (número errado, não erro) que motivou a ferramenta.
     *
     * `pg_get_constraintdef()` devolve a definição já legível ("FOREIGN KEY (tenant_id)
     * REFERENCES tenant(id)"), que é o formato mais útil para quem vai escrever o SQL.
     *
     * @return list<array<string, mixed>>
     */
    private function chaves(string $tabela): array
    {
        $resultado = $this->conexao->consultar(
            "SELECT conname AS chave,
                    CASE contype
                        WHEN 'p' THEN 'PRIMARY KEY'
                        WHEN 'f' THEN 'FOREIGN KEY'
                        ELSE 'UNIQUE'
                    END AS tipo,
                    pg_get_constraintdef(oid) AS definicao
             FROM pg_constraint
             WHERE contype IN ('f', 'p', 'u')
               AND conrelid = (
                   SELECT c.oid FROM pg_class c
                   WHERE c.relnamespace = 'public'::regnamespace AND c.relname = :tabela
               )
             ORDER BY contype, conname",
            self::LIMITE,
            ['tabela' => $tabela],
        );

        return $resultado['linhas'];
    }

    /** @return list<array<string, mixed>> */
    private function indices(string $tabela): array
    {
        $resultado = $this->conexao->consultar(
            "SELECT indexname AS indice, indexdef AS definicao
             FROM pg_indexes
             WHERE schemaname = 'public' AND tablename = :tabela
             ORDER BY indexname",
            self::LIMITE,
            ['tabela' => $tabela],
        );

        return $resultado['linhas'];
    }
}
