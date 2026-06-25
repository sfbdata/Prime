<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Isola o domínio Agenda por escritório (S4/P0 da remediação multi-tenant).
 *
 * Adiciona tenant_id em `evento` e `legenda_cor`. Estratégia, espelhando S2/S3:
 *   - evento: coluna nullable -> backfill via autor (criador_id -> user_tenant ativo) ->
 *     fallback de tenant único -> SET NOT NULL + FK + índice. Em dev os 6 eventos resolvem
 *     pelo criador; em ambiente multi-tenant com órfão remanescente, o NOT NULL aborta (rollback).
 *   - legenda_cor: catálogo de cores sem dono nem relação a evento (a cor do evento é string
 *     hex, não FK). Vira config por-tenant; backfill SÓ pelo fallback de tenant único (no dev a
 *     tabela está vazia -> 0 linhas afetadas). Em prod multi-tenant com legendas globais, a
 *     decisão de negócio é duplicar o catálogo por tenant (seed) — não adivinhar por cor.
 *
 * `evento_participante` NÃO ganha tenant: herda via `evento` (já TenantAware).
 */
final class Version20260625200952 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tenant_id em evento e legenda_cor para isolar a agenda por escritório';
    }

    public function up(Schema $schema): void
    {
        // ---- evento (backfill via autor) ----
        $this->addSql('ALTER TABLE evento ADD tenant_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE evento SET tenant_id = (
                SELECT ut.tenant_id FROM user_tenant ut
                WHERE ut.user_id = evento.criador_id AND ut.is_active = true
                ORDER BY ut.id ASC LIMIT 1
            ) WHERE tenant_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE evento SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
            WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
        SQL);
        $this->addSql('ALTER TABLE evento ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE evento ADD CONSTRAINT FK_47860B059033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_47860B059033212A ON evento (tenant_id)');

        // ---- legenda_cor (config por-tenant; sem âncora -> fallback de tenant único) ----
        $this->addSql('ALTER TABLE legenda_cor ADD tenant_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE legenda_cor SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
            WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
        SQL);
        $this->addSql('ALTER TABLE legenda_cor ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE legenda_cor ADD CONSTRAINT FK_CFA111699033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_CFA111699033212A ON legenda_cor (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE legenda_cor DROP CONSTRAINT FK_CFA111699033212A');
        $this->addSql('DROP INDEX IDX_CFA111699033212A');
        $this->addSql('ALTER TABLE legenda_cor DROP tenant_id');
        $this->addSql('ALTER TABLE evento DROP CONSTRAINT FK_47860B059033212A');
        $this->addSql('DROP INDEX IDX_47860B059033212A');
        $this->addSql('ALTER TABLE evento DROP tenant_id');
    }
}
