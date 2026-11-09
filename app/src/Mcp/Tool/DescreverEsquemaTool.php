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
 *
 * Lista e descreve pelo catálogo do PostgreSQL, em todos os schemas da aplicação (todos menos
 * `information_schema` e os `pg_*`; spec voto-redes-do-schema §2): o `information_schema` esconde a
 * tabela em que a role de leitura não tem privilégio, e a tabela continua existindo. Nome sem
 * prefixo no `public` (`cliente`) e qualificado fora dele (`voto.liberacao`). Só lê o catálogo:
 * nenhuma permissão nova para a role do MCP.
 */
final class DescreverEsquemaTool
{
    private const LIMITE = 2000;

    private const SQL_TABELAS = <<<'SQL'
        SELECT CASE WHEN n.nspname = 'public' THEN c.relname ELSE n.nspname || '.' || c.relname END AS tabela
          FROM pg_class c
          JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE c.relkind IN ('r', 'p')
           AND n.nspname <> 'information_schema' AND n.nspname NOT LIKE 'pg\_%'
         ORDER BY n.nspname, c.relname
        SQL;

    public function __construct(
        private readonly ConexaoLeitura $conexao,
    ) {}

    /**
     * Descreve o esquema do banco do JusPrime.
     *
     * Sem argumento, lista todas as tabelas: as do schema public pelo nome (cliente) e as de outro
     * schema com o schema na frente (voto.liberacao). Com o nome de uma tabela, devolve suas colunas
     * (tipo, se aceita nulo, valor padrão), suas chaves (primária, estrangeiras e únicas — as
     * estrangeiras dizem para qual tabela e coluna apontam, use-as para montar o JOIN certo) e
     * seus índices. Use SEMPRE antes de escrever uma consulta contra uma tabela que você não
     * conhece.
     *
     * @param string|null $tabela Nome da tabela a descrever, como aparece na lista; omita para listar todas
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
                    'A tabela "%s" não existe no banco. Rode descrever_esquema sem argumento para ver '
                    . 'a lista (a tabela de outro schema vem com o schema na frente, como voto.liberacao).',
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
        $resultado = $this->conexao->consultar(self::SQL_TABELAS, self::LIMITE);

        return array_map(
            static fn (array $linha): string => (string) $linha['tabela'],
            $resultado['linhas'],
        );
    }

    /**
     * Schema e nome, separados no ponto; sem ponto, o schema é o `public`. Só roda depois de o
     * nome ter sido conferido contra a lista.
     *
     * @return array{schema: string, nome: string}
     */
    private function partes(string $tabela): array
    {
        if (!str_contains($tabela, '.')) {
            return ['schema' => 'public', 'nome' => $tabela];
        }

        [$schema, $nome] = explode('.', $tabela, 2);

        return ['schema' => $schema, 'nome' => $nome];
    }

    /**
     * Colunas pelo catálogo (`pg_attribute`): o tipo vem de `format_type` (com tamanho e precisão,
     * como `character varying(255)`) e o padrão de `pg_get_expr`.
     *
     * @return list<array<string, mixed>>
     */
    private function colunas(string $tabela): array
    {
        $resultado = $this->conexao->consultar(
            "SELECT a.attname AS coluna,
                    format_type(a.atttypid, a.atttypmod) AS tipo,
                    CASE WHEN a.attnotnull THEN 'NAO' ELSE 'SIM' END AS aceita_nulo,
                    pg_get_expr(d.adbin, d.adrelid) AS padrao
             FROM pg_attribute a
             JOIN pg_class c ON c.oid = a.attrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             WHERE n.nspname = :schema AND c.relname = :nome AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum",
            self::LIMITE,
            $this->partes($tabela),
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
                   JOIN pg_namespace n ON n.oid = c.relnamespace
                   WHERE n.nspname = :schema AND c.relname = :nome
               )
             ORDER BY contype, conname",
            self::LIMITE,
            $this->partes($tabela),
        );

        return $resultado['linhas'];
    }

    /** @return list<array<string, mixed>> */
    private function indices(string $tabela): array
    {
        $resultado = $this->conexao->consultar(
            "SELECT indexname AS indice, indexdef AS definicao
             FROM pg_indexes
             WHERE schemaname = :schema AND tablename = :nome
             ORDER BY indexname",
            self::LIMITE,
            $this->partes($tabela),
        );

        return $resultado['linhas'];
    }
}
