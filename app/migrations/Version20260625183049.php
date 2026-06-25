<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Isola o domínio Cliente por escritório (S2/P0 da remediação multi-tenant).
 *
 * Adiciona tenant_id em `cliente` (tabela base da herança JOINED; ClientePF/ClientePJ
 * herdam a identidade pela PK compartilhada, então a coluna fica só na base) e em
 * `cliente_documento`. Estratégia, espelhando Version20260625120342 (ServiceDesk):
 *   1. cria a coluna nullable;
 *   2. backfill natural: autor (criado_por_id) -> vínculo user_tenant ativo mais antigo;
 *   3. fallback determinístico p/ órfãos SÓ quando existe um único tenant (dev/escritório
 *      único); em ambiente multi-tenant o fallback NÃO dispara;
 *   4. SET NOT NULL — se ainda sobrar órfão (multi-tenant com autor não resolvível), o
 *      Postgres aborta a migration inteira (rollback), sem chutar tenant. Corrigir os
 *      dados antes de re-aplicar. cliente_documento herda o tenant do cliente pai.
 */
final class Version20260625183049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tenant_id em cliente e cliente_documento para isolar clientes por escritório';
    }

    public function up(Schema $schema): void
    {
        // ---- Cliente (tabela base da herança JOINED) ----
        $this->addSql('ALTER TABLE cliente ADD tenant_id INT DEFAULT NULL');

        // 1) backfill natural: autor -> vínculo de tenant ativo mais antigo
        $this->addSql(<<<'SQL'
            UPDATE cliente SET tenant_id = (
                SELECT ut.tenant_id FROM user_tenant ut
                WHERE ut.user_id = cliente.criado_por_id AND ut.is_active = true
                ORDER BY ut.id ASC LIMIT 1
            ) WHERE tenant_id IS NULL
        SQL);

        // 2) fallback determinístico para órfãos SÓ quando existe um único tenant
        $this->addSql(<<<'SQL'
            UPDATE cliente SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
            WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
        SQL);

        // 3) trava de segurança: órfão remanescente (multi-tenant) -> NOT NULL aborta tudo
        $this->addSql('ALTER TABLE cliente ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE cliente ADD CONSTRAINT FK_F41C9B259033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F41C9B259033212A ON cliente (tenant_id)');

        // ---- ClienteDocumento (herda o tenant do cliente pai; cliente_id é NOT NULL) ----
        $this->addSql('ALTER TABLE cliente_documento ADD tenant_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE cliente_documento d SET tenant_id = c.tenant_id
            FROM cliente c WHERE c.id = d.cliente_id AND d.tenant_id IS NULL
        SQL);
        $this->addSql('ALTER TABLE cliente_documento ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE cliente_documento ADD CONSTRAINT FK_A1BCA1039033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A1BCA1039033212A ON cliente_documento (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cliente_documento DROP CONSTRAINT FK_A1BCA1039033212A');
        $this->addSql('DROP INDEX IDX_A1BCA1039033212A');
        $this->addSql('ALTER TABLE cliente_documento DROP tenant_id');
        $this->addSql('ALTER TABLE cliente DROP CONSTRAINT FK_F41C9B259033212A');
        $this->addSql('DROP INDEX IDX_F41C9B259033212A');
        $this->addSql('ALTER TABLE cliente DROP tenant_id');
    }
}
