<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260522180222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pasta ADD tenant_id INT NOT NULL');
        $this->addSql('ALTER TABLE pasta ADD CONSTRAINT FK_9B3BBC819033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_pasta_tenant ON pasta (tenant_id)');
        $this->addSql('ALTER TABLE pasta_documento ADD tenant_id INT NOT NULL');
        $this->addSql('ALTER TABLE pasta_documento ADD CONSTRAINT FK_69D2B3819033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_pasta_documento_tenant ON pasta_documento (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pasta DROP CONSTRAINT FK_9B3BBC819033212A');
        $this->addSql('DROP INDEX idx_pasta_tenant');
        $this->addSql('ALTER TABLE pasta DROP tenant_id');
        $this->addSql('ALTER TABLE pasta_documento DROP CONSTRAINT FK_69D2B3819033212A');
        $this->addSql('DROP INDEX idx_pasta_documento_tenant');
        $this->addSql('ALTER TABLE pasta_documento DROP tenant_id');
    }
}
