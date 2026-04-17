<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260417165125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refatorar tarefas: responsavel direto, criador, data_alteracao; remover atribuicao_tarefa e arquivos_admin';
    }

    public function up(Schema $schema): void
    {
        // Remove tabela legada de atribuições (substituída por responsavel_id direto na tarefa)
        $this->addSql('DROP TABLE IF EXISTS atribuicao_tarefa');

        // Remove tarefas sem pasta (dados legados sem vínculo)
        $this->addSql('DELETE FROM tarefa WHERE pasta_id IS NULL');

        $this->addSql('ALTER TABLE tarefa DROP CONSTRAINT fk_31b4cba7fcdbc8c');
        $this->addSql('ALTER TABLE tarefa ADD data_alteracao TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa ADD criado_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa ADD responsavel_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa DROP arquivos_admin');
        $this->addSql('ALTER TABLE tarefa ALTER pasta_id SET NOT NULL');
        $this->addSql('ALTER TABLE tarefa ADD CONSTRAINT FK_31B4CBA7FCDBC8C FOREIGN KEY (pasta_id) REFERENCES pasta (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tarefa ADD CONSTRAINT FK_31B4CBAF42F4A03 FOREIGN KEY (criado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tarefa ADD CONSTRAINT FK_31B4CBABB9AF004 FOREIGN KEY (responsavel_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_31B4CBAF42F4A03 ON tarefa (criado_por_id)');
        $this->addSql('CREATE INDEX IDX_31B4CBABB9AF004 ON tarefa (responsavel_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa DROP CONSTRAINT FK_31B4CBA7FCDBC8C');
        $this->addSql('ALTER TABLE tarefa DROP CONSTRAINT FK_31B4CBAF42F4A03');
        $this->addSql('ALTER TABLE tarefa DROP CONSTRAINT FK_31B4CBABB9AF004');
        $this->addSql('DROP INDEX IDX_31B4CBAF42F4A03');
        $this->addSql('DROP INDEX IDX_31B4CBABB9AF004');
        $this->addSql('ALTER TABLE tarefa ADD arquivos_admin JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa DROP data_alteracao');
        $this->addSql('ALTER TABLE tarefa DROP criado_por_id');
        $this->addSql('ALTER TABLE tarefa DROP responsavel_id');
        $this->addSql('ALTER TABLE tarefa ALTER pasta_id DROP NOT NULL');
        $this->addSql('ALTER TABLE tarefa ADD CONSTRAINT fk_31b4cba7fcdbc8c FOREIGN KEY (pasta_id) REFERENCES pasta (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
