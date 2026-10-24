<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dashboard PR3 (spec `docs/specs/dashboard-parte-1.md`), a única migration da frente:
 *
 * - `pasta.finalizada_em`: quando a pasta chegou à etapa Finalizada do andamento ("Pastas concluídas"),
 *   mantida pelo `PastaAndamento::gravar()` da via atual. Backfill em duas etapas:
 *   (a) pasta com andamento gravado na via atual (`administrativa` → via `administrativa`, senão
 *       `judicial`) cuja fase atual é a ÚLTIMA da trilha: a data do marco dela (`marcos.em[atual]`). A
 *       última da trilha é a do `AndamentoDaPasta::fases()`: as 10 da base (judicial e administrativa têm
 *       10) mais as extras de reativação, mais um "Em andamento" para cada "Reativada" que não vem seguida
 *       dele. O marco guarda o horário local com o fuso (DATE_ATOM): os 19 primeiros caracteres são o
 *       horário local, o mesmo que o PHP grava na coluna. Vale também para a pasta excluída: a lápide não
 *       mexe no andamento, e a contagem a tira pelo excluida_em;
 *   (b) pasta arquivada, não excluída, SEM andamento gravado na via atual (regra confirmada pelo dono
 *       em 23/10): a data do arquivamento, o mais recente entre o cartão do Registro (`pasta_mensagem`,
 *       sistema `situacao`, "para "Arquivado"") e o `audit_log` (update com situacao → arquivado; a
 *       auditoria de criação não guarda o id da pasta). Vale o mais recente dos dois porque o "Editar
 *       dados" já arquivou sem cartão no Registro, só com auditoria. Sem nenhuma fonte, fica nula (a
 *       contagem sai no log da migration) e a pasta não entra em período nenhum.
 * - `dashboard_zeramento`: o "Zerar" de coluna (DASH-014), por pessoa ou para todos (`user_id` nulo).
 * - `dashboard_meta_pastas`: meta de pastas do escritório a partir de um mês (DASH-016).
 *
 * Os SQL do backfill são constantes públicas para o teste de integração (`BackfillDaFinalizadaEmTest`).
 */
final class Version20261023150900 extends AbstractMigration
{
    /** Etapa (a): pastas cuja fase gravada na via atual é a Finalizada (última da trilha, com as extras). */
    public const SQL_BACKFILL_PELO_ANDAMENTO = <<<'SQL'
        WITH trilha AS (
            SELECT a.pasta_id, a.atual, a.atualizado_em, a.marcos::jsonb -> 'em' AS em,
                   10 + (SELECT COUNT(*) + COUNT(*) FILTER (WHERE x.nome = 'Reativada' AND COALESCE(x.proxima, '') <> 'Em andamento')
                           FROM (SELECT e.fase ->> 0 AS nome, LEAD(e.fase ->> 0) OVER (ORDER BY e.ordem) AS proxima
                                   FROM jsonb_array_elements(CASE WHEN jsonb_typeof(a.marcos::jsonb -> 'extra') = 'array'
                                                                  THEN a.marcos::jsonb -> 'extra' ELSE '[]'::jsonb END)
                                        WITH ORDINALITY AS e(fase, ordem)) x) AS fases
              FROM pasta_andamento a
              JOIN pasta p ON p.id = a.pasta_id
             WHERE a.via = CASE WHEN p.administrativa THEN 'administrativa' ELSE 'judicial' END
        )
        UPDATE pasta p
           SET finalizada_em = COALESCE(LEFT(NULLIF(t.em ->> t.atual::text, ''), 19)::timestamp, t.atualizado_em)
          FROM trilha t
         WHERE t.pasta_id = p.id
           AND t.atual = t.fases - 1
        SQL;

    /** Etapa (b): último arquivamento das pastas arquivadas, não excluídas e sem andamento na via atual. */
    public const SQL_BACKFILL_PELO_ARQUIVAMENTO = <<<'SQL'
        WITH pelo_registro AS (
            SELECT m.pasta_id, MAX(m.criada_em) AS em
              FROM pasta_mensagem m
             WHERE m.sistema = 'situacao'
               AND (m.conteudo LIKE '%para &quot;Arquivado&quot;%' OR m.conteudo LIKE '%para "Arquivado"%')
             GROUP BY m.pasta_id
        ),
        pela_auditoria AS (
            SELECT a.entity_id, MAX(a.created_at) AS em
              FROM audit_log a
             WHERE a.entity_class = 'App\Pasta\Entity\Pasta'
               AND a.action = 'update'
               AND a.changes::jsonb #>> '{diff,changes,situacao,to}' = 'arquivado'
             GROUP BY a.entity_id
        )
        UPDATE pasta p
           SET finalizada_em = GREATEST(
                   (SELECT r.em FROM pelo_registro r WHERE r.pasta_id = p.id),
                   (SELECT a.em FROM pela_auditoria a WHERE a.entity_id = p.id::text))
         WHERE p.situacao = 'arquivado'
           AND p.excluida_em IS NULL
           AND NOT EXISTS (SELECT 1 FROM pasta_andamento a
                            WHERE a.pasta_id = p.id
                              AND a.via = CASE WHEN p.administrativa THEN 'administrativa' ELSE 'judicial' END)
        SQL;

    /** Pastas da etapa (b) sem nenhuma das duas fontes: ficam com finalizada_em nula. */
    public const SQL_CONTA_SEM_FONTE = <<<'SQL'
        SELECT COUNT(*)
          FROM pasta p
         WHERE p.situacao = 'arquivado'
           AND p.excluida_em IS NULL
           AND NOT EXISTS (SELECT 1 FROM pasta_andamento a
                            WHERE a.pasta_id = p.id
                              AND a.via = CASE WHEN p.administrativa THEN 'administrativa' ELSE 'judicial' END)
           AND NOT EXISTS (SELECT 1 FROM pasta_mensagem m
                            WHERE m.pasta_id = p.id AND m.sistema = 'situacao'
                              AND (m.conteudo LIKE '%para &quot;Arquivado&quot;%' OR m.conteudo LIKE '%para "Arquivado"%'))
           AND NOT EXISTS (SELECT 1 FROM audit_log a
                            WHERE a.entity_class = 'App\Pasta\Entity\Pasta' AND a.entity_id = p.id::text
                              AND a.action = 'update'
                              AND a.changes::jsonb #>> '{diff,changes,situacao,to}' = 'arquivado')
        SQL;

    public function getDescription(): string
    {
        return 'Dashboard: pasta.finalizada_em, zeramento de coluna e meta de pastas';
    }

    public function up(Schema $schema): void
    {
        $this->contarPastasSemFonte();

        $this->addSql('ALTER TABLE pasta ADD finalizada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql(self::SQL_BACKFILL_PELO_ANDAMENTO);
        $this->addSql(self::SQL_BACKFILL_PELO_ARQUIVAMENTO);

        $this->criarZeramento();
        $this->criarMetaDePastas();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE dashboard_zeramento');
        $this->addSql('DROP TABLE dashboard_meta_pastas');
        $this->addSql('ALTER TABLE pasta DROP finalizada_em');
    }

    private function criarZeramento(): void
    {
        $this->addSql('CREATE TABLE dashboard_zeramento (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, desfeito_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, coluna VARCHAR(40) NOT NULL, contar_desde TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, desfeito_por_id INT DEFAULT NULL, tenant_id INT NOT NULL, user_id INT DEFAULT NULL, por_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_526A24E89033212A ON dashboard_zeramento (tenant_id)');
        $this->addSql('CREATE INDEX idx_dashboard_zeramento_tenant_coluna ON dashboard_zeramento (tenant_id, coluna)');
        $this->addSql('CREATE INDEX idx_dashboard_zeramento_user ON dashboard_zeramento (user_id)');
        $this->addSql('CREATE INDEX idx_dashboard_zeramento_por ON dashboard_zeramento (por_id)');
        $this->addSql('CREATE INDEX idx_dashboard_zeramento_desfeito_por ON dashboard_zeramento (desfeito_por_id)');
        $this->addSql('ALTER TABLE dashboard_zeramento ADD CONSTRAINT FK_526A24E8E2A02EB9 FOREIGN KEY (desfeito_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dashboard_zeramento ADD CONSTRAINT FK_526A24E89033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dashboard_zeramento ADD CONSTRAINT FK_526A24E8A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dashboard_zeramento ADD CONSTRAINT FK_526A24E8F9B438CB FOREIGN KEY (por_id) REFERENCES "user" (id) NOT DEFERRABLE');
    }

    private function criarMetaDePastas(): void
    {
        $this->addSql('CREATE TABLE dashboard_meta_pastas (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, mes DATE NOT NULL, criada_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, valor INT NOT NULL, tenant_id INT NOT NULL, definida_por_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C953CBA89033212A ON dashboard_meta_pastas (tenant_id)');
        $this->addSql('CREATE INDEX idx_dashboard_meta_pastas_tenant_mes ON dashboard_meta_pastas (tenant_id, mes)');
        $this->addSql('CREATE INDEX idx_dashboard_meta_pastas_definida_por ON dashboard_meta_pastas (definida_por_id)');
        $this->addSql('ALTER TABLE dashboard_meta_pastas ADD CONSTRAINT FK_C953CBA89033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dashboard_meta_pastas ADD CONSTRAINT FK_C953CBA8A3777306 FOREIGN KEY (definida_por_id) REFERENCES "user" (id) NOT DEFERRABLE');
    }

    private function contarPastasSemFonte(): void
    {
        $semFonte = (int) $this->connection->fetchOne(self::SQL_CONTA_SEM_FONTE);

        $this->write(sprintf('Pastas arquivadas sem andamento na via atual e sem data de arquivamento (sem cartão no Registro nem auditoria): %d', $semFonte));
    }
}
