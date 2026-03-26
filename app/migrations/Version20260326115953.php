<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260326115953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE processo ADD criado_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD modificado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD criado_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD CONSTRAINT FK_16E5B82DF42F4A03 FOREIGN KEY (criado_por_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_16E5B82DF42F4A03 ON processo (criado_por_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE processo DROP CONSTRAINT FK_16E5B82DF42F4A03');
        $this->addSql('DROP INDEX IDX_16E5B82DF42F4A03');
        $this->addSql('ALTER TABLE processo DROP criado_at');
        $this->addSql('ALTER TABLE processo DROP modificado_em');
        $this->addSql('ALTER TABLE processo DROP criado_por_id');
    }
}
