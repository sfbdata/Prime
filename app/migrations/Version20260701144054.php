<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sync Drive (Fase 0): remove a unicidade do NUP da pasta (passa a poder repetir,
 * pois o acervo do Drive tem NUPs repetidos) e adiciona a identidade de sincronização
 * (drive_folder_id na pasta, drive_file_id no documento). O isolamento de tenant NÃO
 * muda: tenant_id e o filtro seguem intactos; cai só a unicidade, virando índice comum.
 */
final class Version20260701144054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync Drive: remove unique de NUP e adiciona vinculo drive_folder_id/drive_file_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_pasta_tenant_nup');
        $this->addSql('ALTER TABLE pasta ADD drive_folder_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta ADD drive_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_pasta_tenant_nup ON pasta (tenant_id, nup)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pasta_drive_folder_id ON pasta (drive_folder_id)');
        $this->addSql('ALTER TABLE pasta_documento ADD drive_file_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_pasta_documento_drive_file_id ON pasta_documento (drive_file_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pasta_tenant_nup');
        $this->addSql('DROP INDEX uniq_pasta_drive_folder_id');
        $this->addSql('ALTER TABLE pasta DROP drive_folder_id');
        $this->addSql('ALTER TABLE pasta DROP drive_synced_at');
        $this->addSql('CREATE UNIQUE INDEX uniq_pasta_tenant_nup ON pasta (tenant_id, nup)');
        $this->addSql('DROP INDEX uniq_pasta_documento_drive_file_id');
        $this->addSql('ALTER TABLE pasta_documento DROP drive_file_id');
    }
}
