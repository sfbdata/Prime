<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `cobranca_acordo.data_acordo` passa a aceitar NULL (2026-08-17) — spec
 * `docs/specs/cobranca-espelho-violacoes-do-importe.md` §2.
 *
 * Decisão do dono: **o sistema é o espelho da contabilidade**. Se a contabilidade tem um acordo sem
 * data, o nosso sistema grava esse acordo sem data. Recusar a criar seria o sistema julgando que um
 * registro incompleto não merece existir — a mesma espécie de opinião que esta frente veio remover.
 *
 * O que esta coluna guardava até aqui, em produção: **375 de 395 acordos (94,9%) com data INVENTADA** —
 * o 1º dia do mês da competência, derivado por `dataAcordoPadrao()` nos importadores de inadimplência e
 * de receitas, porque nenhum dos dois relatórios traz a data e a coluna era NOT NULL. Não era inócuo:
 * R$ 203.265,07 de encargo foi materializado sobre essa data, e **256 dívidas ficaram com encargo
 * R$ 0,00** porque a data inventada precedia o próprio vencimento delas (pior caso: 679 dias antes).
 *
 * Escrita à mão, não gerada: `make:migration` roda contra o banco do dev — e a worktree nem herda o
 * `.env.local`, então apontaria para `saas` (parado no tempo) em vez de `saas_ux`. O diff viria cheio
 * de alteração de outras frentes e de `DROP INDEX` em índice funcional. Aqui só entra esta frente.
 *
 * ⚠️ Esta migration NÃO conserta o passivo. As 375 datas inventadas continuam gravadas; o conserto
 * delas é reprocessamento (obrigatório, mas com simulação e sob autorização do dono), não DDL. O que
 * ela faz é **permitir que o vazio exista** — sem isso o importador continua obrigado a inventar.
 */
final class Version20260817180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cobranca_acordo.data_acordo aceita NULL (acordo sem data na contabilidade)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_acordo ALTER COLUMN data_acordo DROP NOT NULL');
    }

    /**
     * A volta só é possível se NENHUM acordo estiver sem data — e é assim de propósito. Repor o NOT NULL
     * inventando uma data (hoje, ou o 1º dia da competência) seria reintroduzir exatamente a violação #3
     * pela porta do rollback, e de forma silenciosa. Se houver acordo sem data, esta migration ABORTA e o
     * operador decide o que fazer com cada um — que é o julgamento da gerência, não do sistema.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE sem_data bigint;
            BEGIN
                SELECT COUNT(*) INTO sem_data FROM cobranca_acordo WHERE data_acordo IS NULL;
                IF sem_data > 0 THEN
                    RAISE EXCEPTION 'Existem % acordo(s) sem data. Repor NOT NULL exigiria inventar data — resolva cada caso antes de reverter.', sem_data;
                END IF;
            END $$;
            SQL);
        $this->addSql('ALTER TABLE cobranca_acordo ALTER COLUMN data_acordo SET NOT NULL');
    }
}
