<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260324180002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa DROP CONSTRAINT fk_5a86847faa822d2');
        $this->addSql('DROP INDEX idx_31b4cbaaaa822d2');
        $this->addSql('ALTER TABLE tarefa RENAME COLUMN processo_id TO pasta_id');
        $this->addSql('UPDATE tarefa SET pasta_id = NULL');
        $this->addSql('ALTER TABLE tarefa ADD CONSTRAINT FK_31B4CBA7FCDBC8C FOREIGN KEY (pasta_id) REFERENCES pasta (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_31B4CBA7FCDBC8C ON tarefa (pasta_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tarefa DROP CONSTRAINT FK_31B4CBA7FCDBC8C');
        $this->addSql('DROP INDEX IDX_31B4CBA7FCDBC8C');
        $this->addSql('ALTER TABLE tarefa RENAME COLUMN pasta_id TO processo_id');
        $this->addSql('ALTER TABLE tarefa ADD CONSTRAINT fk_5a86847faa822d2 FOREIGN KEY (processo_id) REFERENCES processo (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_31b4cbaaaa822d2 ON tarefa (processo_id)');
    }
}
