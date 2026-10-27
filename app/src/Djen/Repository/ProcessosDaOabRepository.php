<?php

declare(strict_types=1);

namespace App\Djen\Repository;

use App\Djen\DTO\LinhaDoProcessoDaOab;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;

/**
 * Leitura da aba "Processos da OAB" do Push (desenho 11). SQL cru com o escritório explícito em toda
 * consulta (não depende do TenantFilter). Devolve o conjunto do escritório; o filtro e a paginação da
 * tela ficam em {@see \App\Djen\Service\FiltroDeProcessosDaOab}, sobre as linhas já em memória.
 */
class ProcessosDaOabRepository
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    /**
     * Processos acompanhados: cadastrados sem baixa e sem pausa, mais os números incluídos na mão
     * que não têm processo cadastrado.
     *
     * @param list<int> $processosOcultos ids de processo que a pessoa não pode ver (cadeado da pasta)
     *
     * @return list<LinhaDoProcessoDaOab>
     */
    public function acompanhados(Tenant $tenant, array $processosOcultos = []): array
    {
        $sql = <<<'SQL'
            WITH proc AS (
                SELECT p.id AS processo_id, regexp_replace(p.numero_processo, '\D', '', 'g') AS num,
                       p.sigla_tribunal, p.orgao_julgador
                FROM processo p
                WHERE p.tenant_id = :t AND p.data_baixa IS NULL AND p.monitoramento_pausado_em IS NULL
                  AND NOT (p.id = ANY(CAST(:ocultos AS int[])))
            ),
            manual AS (
                SELECT a.numero_processo AS num
                FROM djen_processo_acompanhado a
                WHERE a.tenant_id = :t AND a.acompanhando
                  AND NOT EXISTS (SELECT 1 FROM proc WHERE proc.num = a.numero_processo)
            ),
            base AS (
                SELECT num, processo_id, sigla_tribunal, orgao_julgador FROM proc
                UNION ALL
                SELECT num, NULL, NULL, NULL FROM manual
            )
            SQL . $this->complemento();

        return $this->linhas($sql, ['t' => $tenant->getId(), 'ocultos' => '{' . implode(',', $processosOcultos) . '}']);
    }

    /**
     * "Buscar processo existente" do modal "Vincular processo" (desenho 11): processos cadastrados no escritório, por
     * número, cliente ou NUP da pasta; até `$limite`. O mais recente primeiro.
     *
     * @param list<int> $processosOcultos ids de processo que a pessoa não pode ver (cadeado da pasta)
     *
     * @return list<array{numero: string, tribunal: string, pasta: ?string}>
     */
    public function buscarCadastrados(Tenant $tenant, string $termo, array $processosOcultos = [], int $limite = 8): array
    {
        $termo = trim($termo);
        $digitos = preg_replace('/\D/', '', $termo) ?? '';
        $sql = <<<'SQL'
            SELECT regexp_replace(p.numero_processo, '\D', '', 'g') AS numero, COALESCE(p.sigla_tribunal, '') AS tribunal,
                   (SELECT pa.nup FROM pasta_processo pr JOIN pasta pa ON pa.id = pr.pasta_id
                     WHERE pr.processo_id = p.id AND pa.excluida_em IS NULL ORDER BY pr.principal DESC, pa.id LIMIT 1) AS pasta
            FROM processo p
            WHERE p.tenant_id = :t AND NOT (p.id = ANY(CAST(:ocultos AS int[])))
              AND (:termo = ''
                   OR (:digitos <> '' AND regexp_replace(p.numero_processo, '\D', '', 'g') LIKE '%' || :digitos || '%')
                   OR EXISTS (SELECT 1 FROM pasta_processo pr JOIN pasta pa ON pa.id = pr.pasta_id
                               WHERE pr.processo_id = p.id AND pa.excluida_em IS NULL
                                 AND (pa.nup ILIKE '%' || :termo || '%' OR pa.nome_cliente ILIKE '%' || :termo || '%')))
            ORDER BY p.id DESC
            LIMIT :limite
            SQL;

        $linhas = $this->conexao->fetchAllAssociative(
            $sql,
            ['t' => $tenant->getId(), 'ocultos' => '{' . implode(',', $processosOcultos) . '}', 'termo' => $termo, 'digitos' => $digitos, 'limite' => $limite],
            ['limite' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (array $l): array => ['numero' => (string) $l['numero'], 'tribunal' => (string) $l['tribunal'], 'pasta' => $l['pasta'] !== null ? (string) $l['pasta'] : null], $linhas);
    }

    /**
     * Relatório: números que a OAB trouxe em publicação e que ainda não são acompanhados.
     *
     * @return list<LinhaDoProcessoDaOab>
     */
    public function relatorio(Tenant $tenant): array
    {
        $sql = <<<'SQL'
            WITH acompanhado AS (
                SELECT regexp_replace(p.numero_processo, '\D', '', 'g') AS num
                FROM processo p
                WHERE p.tenant_id = :t AND p.data_baixa IS NULL AND p.monitoramento_pausado_em IS NULL
                UNION
                SELECT a.numero_processo FROM djen_processo_acompanhado a WHERE a.tenant_id = :t AND a.acompanhando
                UNION
                SELECT a.numero_processo FROM djen_processo_acompanhado a WHERE a.tenant_id = :t AND a.excluido_em IS NOT NULL
            ),
            base AS (
                SELECT DISTINCT regexp_replace(d.numero_processo, '\D', '', 'g') AS num,
                       (SELECT p2.id FROM processo p2 WHERE p2.tenant_id = :t AND regexp_replace(p2.numero_processo, '\D', '', 'g') = regexp_replace(d.numero_processo, '\D', '', 'g') ORDER BY p2.id LIMIT 1) AS processo_id,
                       NULL::varchar AS sigla_tribunal, NULL::varchar AS orgao_julgador
                FROM publicacao_djen d
                WHERE d.tenant_id = :t AND d.oab_monitorada_id IS NOT NULL
                  AND regexp_replace(d.numero_processo, '\D', '', 'g') NOT IN (SELECT num FROM acompanhado)
            )
            SQL . $this->complemento();

        return $this->linhas($sql, ['t' => $tenant->getId(), 'ocultos' => '{}']);
    }

    /**
     * Junta, a partir de `base`, a última publicação (tribunal, órgão, OAB, data), as anotações, as partes e a pasta.
     */
    private function complemento(): string
    {
        return <<<'SQL'

            , pub AS (
                SELECT DISTINCT ON (regexp_replace(d.numero_processo, '\D', '', 'g'))
                       regexp_replace(d.numero_processo, '\D', '', 'g') AS num,
                       d.sigla_tribunal, d.nome_orgao, d.data_disponibilizacao AS ultima,
                       (SELECT o.numero || '/' || o.uf FROM oab_monitorada o WHERE o.id = d.oab_monitorada_id) AS oab
                FROM publicacao_djen d
                WHERE d.tenant_id = :t
                ORDER BY regexp_replace(d.numero_processo, '\D', '', 'g'), d.data_disponibilizacao DESC NULLS LAST, d.id DESC
            ),
            total AS (
                SELECT regexp_replace(d.numero_processo, '\D', '', 'g') AS num, COUNT(*) AS n
                FROM publicacao_djen d WHERE d.tenant_id = :t GROUP BY 1
            )
            SELECT b.num, b.processo_id,
                   COALESCE(NULLIF(b.sigla_tribunal, ''), pub.sigla_tribunal, '') AS tribunal,
                   COALESCE((SELECT string_agg(x.nome, ' x ' ORDER BY x.id) FROM (SELECT pp.id, pp.nome FROM parte_processo pp WHERE pp.processo_id = b.processo_id ORDER BY pp.id LIMIT 2) x), '') AS partes,
                   COALESCE(NULLIF(b.orgao_julgador, ''), pub.nome_orgao, '') AS orgao,
                   an.area, an.fase, an.observacao, an.email_alternativo,
                   pub.ultima, pub.oab,
                   (SELECT pa.id FROM pasta_processo pr JOIN pasta pa ON pa.id = pr.pasta_id WHERE pr.processo_id = b.processo_id AND pa.excluida_em IS NULL ORDER BY pr.principal DESC, pa.id LIMIT 1) AS pasta_id,
                   (SELECT pa.nup FROM pasta_processo pr JOIN pasta pa ON pa.id = pr.pasta_id WHERE pr.processo_id = b.processo_id AND pa.excluida_em IS NULL ORDER BY pr.principal DESC, pa.id LIMIT 1) AS pasta_nup,
                   COALESCE(total.n, 0) AS publicacoes
            FROM base b
            LEFT JOIN pub ON pub.num = b.num
            LEFT JOIN total ON total.num = b.num
            LEFT JOIN djen_processo_acompanhado an ON an.tenant_id = :t AND an.numero_processo = b.num
            ORDER BY b.num
            SQL;
    }

    /**
     * @param array{t: int|null, ocultos: string} $parametros
     *
     * @return list<LinhaDoProcessoDaOab>
     */
    private function linhas(string $sql, array $parametros): array
    {
        $registros = $this->conexao->fetchAllAssociative($sql, $parametros);

        return array_map(static fn (array $r): LinhaDoProcessoDaOab => new LinhaDoProcessoDaOab(
            (string) $r['num'],
            $r['processo_id'] !== null ? (int) $r['processo_id'] : null,
            (string) $r['tribunal'],
            (string) $r['partes'],
            (string) $r['orgao'],
            $r['area'] ?? null,
            $r['fase'] ?? null,
            $r['observacao'] ?? null,
            $r['email_alternativo'] ?? null,
            $r['ultima'] !== null ? new \DateTimeImmutable((string) $r['ultima']) : null,
            $r['oab'] ?? null,
            $r['pasta_id'] !== null ? (int) $r['pasta_id'] : null,
            $r['pasta_nup'] ?? null,
            (int) $r['publicacoes'],
        ), $registros);
    }
}
