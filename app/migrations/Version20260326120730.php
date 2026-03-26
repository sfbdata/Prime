<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260326120730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pasta ADD modificado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta ADD criado_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta ADD CONSTRAINT FK_9B3BBC81F42F4A03 FOREIGN KEY (criado_por_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9B3BBC81F42F4A03 ON pasta (criado_por_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pasta DROP CONSTRAINT FK_9B3BBC81F42F4A03');
        $this->addSql('DROP INDEX IDX_9B3BBC81F42F4A03');
        $this->addSql('ALTER TABLE pasta DROP modificado_em');
        $this->addSql('ALTER TABLE pasta DROP criado_por_id');
    }
}
