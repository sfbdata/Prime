<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260417172844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tarefa_responsaveis (tarefa_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (tarefa_id, user_id))');
        $this->addSql('CREATE INDEX IDX_6DB972A478217710 ON tarefa_responsaveis (tarefa_id)');
        $this->addSql('CREATE INDEX IDX_6DB972A4A76ED395 ON tarefa_responsaveis (user_id)');
        $this->addSql('ALTER TABLE tarefa_responsaveis ADD CONSTRAINT FK_6DB972A478217710 FOREIGN KEY (tarefa_id) REFERENCES tarefa (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tarefa_responsaveis ADD CONSTRAINT FK_6DB972A4A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tarefa DROP CONSTRAINT fk_31b4cbabb9af004');
        $this->addSql('DROP INDEX idx_31b4cbabb9af004');
        $this->addSql('ALTER TABLE tarefa DROP responsavel_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tarefa_responsaveis DROP CONSTRAINT FK_6DB972A478217710');
        $this->addSql('ALTER TABLE tarefa_responsaveis DROP CONSTRAINT FK_6DB972A4A76ED395');
        $this->addSql('DROP TABLE tarefa_responsaveis');
        $this->addSql('ALTER TABLE tarefa ADD responsavel_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa ADD CONSTRAINT fk_31b4cbabb9af004 FOREIGN KEY (responsavel_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_31b4cbabb9af004 ON tarefa (responsavel_id)');
    }
}
