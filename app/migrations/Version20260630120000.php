<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Isola os acessos granulares por escritório (frente 4 / B3 do B5 — escopo do super-admin).
 *
 * Adiciona tenant_id em `resource_access`, que passa a ser TenantAware. Sem isso, o TenantFilter não
 * tocava as queries de ResourceAccess: a remoção de acesso (`removeResourceAccess`) e a checagem de
 * autorização (`PermissionChecker::canAccessResource`) dependiam só da posse do usuário, deixando um
 * write/IDOR cross-tenant residual para o usuário multi-tenant (um admin de A podia remover, pela URL
 * de A, o grant desse usuário a um recurso de B). Com a coluna, a trava/filtro escopa tudo no tenant.
 *
 * Backfill (o ResourceAccess pertence ao escritório DONO do recurso que ele referencia):
 *   1. coluna nullable.
 *   2. backfill pelo tenant do recurso (cliente/pasta/processo) apontado por resource_type+resource_id.
 *   3. fallback determinístico para tenant único (prod = 1 escritório → cobre tudo, inclusive órfãos
 *      cujo recurso já foi removido).
 *   4. SET NOT NULL + FK + índice. Se sobrar NULL (recurso inexistente em instalação multi-tenant, não
 *      derivável), o NOT NULL ABORTA de propósito — caso ambíguo exige resolução manual antes do deploy.
 *
 * `cliente` usa herança JOINED → tenant_id mora na tabela base `cliente` (subclasses PF/PJ herdam).
 *
 * Aplicar ISOLADA via `migrations:execute --up` — o ledger não tem as 2 migrations antigas do Ponto
 * (Version20260401000000/Version20260408180237), então `migrate` puro é inseguro.
 */
final class Version20260630120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tenant_id em resource_access (acessos granulares isolados por escritório)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resource_access ADD tenant_id INT DEFAULT NULL');

        // 1) backfill pelo tenant do recurso referenciado (a fonte autoritativa do escopo)
        $this->addSql(<<<'SQL'
            UPDATE resource_access ra SET tenant_id = (SELECT c.tenant_id FROM cliente c WHERE c.id = ra.resource_id)
            WHERE ra.tenant_id IS NULL AND ra.resource_type = 'cliente'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE resource_access ra SET tenant_id = (SELECT p.tenant_id FROM pasta p WHERE p.id = ra.resource_id)
            WHERE ra.tenant_id IS NULL AND ra.resource_type = 'pasta'
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE resource_access ra SET tenant_id = (SELECT pr.tenant_id FROM processo pr WHERE pr.id = ra.resource_id)
            WHERE ra.tenant_id IS NULL AND ra.resource_type = 'processo'
        SQL);

        // 2) fallback determinístico para tenant único (cobre órfãos cujo recurso foi removido)
        $this->addSql(<<<'SQL'
            UPDATE resource_access SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
            WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
        SQL);

        // 3) trava: NULL remanescente (recurso inexistente em multi-tenant) -> NOT NULL aborta (rollback)
        $this->addSql('ALTER TABLE resource_access ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE resource_access ADD CONSTRAINT FK_CE95C1AE9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_CE95C1AE9033212A ON resource_access (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE resource_access DROP CONSTRAINT FK_CE95C1AE9033212A');
        $this->addSql('DROP INDEX IDX_CE95C1AE9033212A');
        $this->addSql('ALTER TABLE resource_access DROP tenant_id');
    }
}
