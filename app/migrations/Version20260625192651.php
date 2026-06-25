<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Isola o domínio Processo por escritório (S3/P0 da remediação multi-tenant).
 *
 * Adiciona tenant_id em `processo` (raiz) e nas 3 filhas (documento_processo,
 * movimentacao_processo, parte_processo). Estratégia, espelhando Cliente
 * (Version20260625183049):
 *   1. processo PRIMEIRO: coluna nullable -> backfill via autor (criado_por_id ->
 *      user_tenant ativo) -> fallback de tenant único p/ órfãos -> SET NOT NULL + FK + índice.
 *      Se sobrar órfão em ambiente multi-tenant, o NOT NULL aborta a migration (rollback).
 *   2. unicidade do número de processo passa de GLOBAL para por-escritório:
 *      DROP do unique global + CREATE do unique composto (tenant_id, numero_processo).
 *   3. filhas herdam o tenant do processo pai (FK processo_id NOT NULL, 0 órfãs estruturais).
 *
 * Ordem importa: as filhas só são preenchidas depois de processo.tenant_id estar populado.
 */
final class Version20260625192651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tenant_id em processo e filhas (documento/movimentacao/parte) e torna o número de processo único por escritório';
    }

    public function up(Schema $schema): void
    {
        // ---- processo (raiz) ----
        $this->addSql('ALTER TABLE processo ADD tenant_id INT DEFAULT NULL');

        // 1) backfill natural: autor -> vínculo de tenant ativo mais antigo
        $this->addSql(<<<'SQL'
            UPDATE processo SET tenant_id = (
                SELECT ut.tenant_id FROM user_tenant ut
                WHERE ut.user_id = processo.criado_por_id AND ut.is_active = true
                ORDER BY ut.id ASC LIMIT 1
            ) WHERE tenant_id IS NULL
        SQL);

        // 2) fallback determinístico para órfãos SÓ quando existe um único tenant
        $this->addSql(<<<'SQL'
            UPDATE processo SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
            WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
        SQL);

        // 3) trava: órfão remanescente (multi-tenant) -> NOT NULL aborta tudo (rollback)
        $this->addSql('ALTER TABLE processo ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE processo ADD CONSTRAINT FK_16E5B82D9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_16E5B82D9033212A ON processo (tenant_id)');

        // unicidade do número: GLOBAL -> por escritório (tenant_id, numero_processo)
        $this->addSql('DROP INDEX uniq_16e5b82db7f27f4d');
        $this->addSql('CREATE UNIQUE INDEX uniq_processo_tenant_numero ON processo (tenant_id, numero_processo)');

        // ---- documento_processo (herda do processo pai) ----
        $this->addSql('ALTER TABLE documento_processo ADD tenant_id INT DEFAULT NULL');
        $this->addSql('UPDATE documento_processo f SET tenant_id = p.tenant_id FROM processo p WHERE p.id = f.processo_id AND f.tenant_id IS NULL');
        $this->addSql('ALTER TABLE documento_processo ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE documento_processo ADD CONSTRAINT FK_42EE57D49033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_42EE57D49033212A ON documento_processo (tenant_id)');

        // ---- movimentacao_processo (herda do processo pai) ----
        $this->addSql('ALTER TABLE movimentacao_processo ADD tenant_id INT DEFAULT NULL');
        $this->addSql('UPDATE movimentacao_processo f SET tenant_id = p.tenant_id FROM processo p WHERE p.id = f.processo_id AND f.tenant_id IS NULL');
        $this->addSql('ALTER TABLE movimentacao_processo ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE movimentacao_processo ADD CONSTRAINT FK_BE435ED59033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BE435ED59033212A ON movimentacao_processo (tenant_id)');

        // ---- parte_processo (herda do processo pai) ----
        $this->addSql('ALTER TABLE parte_processo ADD tenant_id INT DEFAULT NULL');
        $this->addSql('UPDATE parte_processo f SET tenant_id = p.tenant_id FROM processo p WHERE p.id = f.processo_id AND f.tenant_id IS NULL');
        $this->addSql('ALTER TABLE parte_processo ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE parte_processo ADD CONSTRAINT FK_87D9D1949033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_87D9D1949033212A ON parte_processo (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parte_processo DROP CONSTRAINT FK_87D9D1949033212A');
        $this->addSql('DROP INDEX IDX_87D9D1949033212A');
        $this->addSql('ALTER TABLE parte_processo DROP tenant_id');
        $this->addSql('ALTER TABLE movimentacao_processo DROP CONSTRAINT FK_BE435ED59033212A');
        $this->addSql('DROP INDEX IDX_BE435ED59033212A');
        $this->addSql('ALTER TABLE movimentacao_processo DROP tenant_id');
        $this->addSql('ALTER TABLE documento_processo DROP CONSTRAINT FK_42EE57D49033212A');
        $this->addSql('DROP INDEX IDX_42EE57D49033212A');
        $this->addSql('ALTER TABLE documento_processo DROP tenant_id');

        $this->addSql('DROP INDEX uniq_processo_tenant_numero');
        $this->addSql('ALTER TABLE processo DROP CONSTRAINT FK_16E5B82D9033212A');
        $this->addSql('DROP INDEX IDX_16E5B82D9033212A');
        $this->addSql('ALTER TABLE processo DROP tenant_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_16e5b82db7f27f4d ON processo (numero_processo)');
    }
}
