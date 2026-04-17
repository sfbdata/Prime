<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260417130331 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove colunas de fluxo de AtribuicaoTarefa: status, data_envio_revisao, arquivos_usuario, descricao_resposta';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE atribuicao_tarefa DROP status');
        $this->addSql('ALTER TABLE atribuicao_tarefa DROP data_envio_revisao');
        $this->addSql('ALTER TABLE atribuicao_tarefa DROP arquivos_usuario');
        $this->addSql('ALTER TABLE atribuicao_tarefa DROP descricao_resposta');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE atribuicao_tarefa ADD status VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE atribuicao_tarefa ADD data_envio_revisao TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE atribuicao_tarefa ADD arquivos_usuario JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE atribuicao_tarefa ADD descricao_resposta TEXT DEFAULT NULL');
    }
}
