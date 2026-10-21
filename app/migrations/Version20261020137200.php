<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P5b (spec docs/specs/processo-cartao-edicao.md): dados do processo preenchidos à mão no cartão.
 *
 * Só acrescenta colunas anuláveis ao `processo` (nulo = sem valor manual); nenhuma linha existente
 * muda e o Datajud continua gravando nas colunas dele.
 */
final class Version20261020137200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P5b: dados manuais do processo (11 campos do cartão, quem e quando)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processo ADD classe_manual VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD assuntos_manual JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD jurisdicao_manual VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD orgao_manual VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD distribuicao_manual DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD valor_causa_manual NUMERIC(15, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD segredo_manual BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD digital_manual BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD gratuita_manual BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD tutela_manual BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD prioridade_manual BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD dados_manuais_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD dados_manuais_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD CONSTRAINT FK_16E5B82D706CB29E FOREIGN KEY (dados_manuais_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_processo_dados_manuais_por ON processo (dados_manuais_por_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processo DROP CONSTRAINT FK_16E5B82D706CB29E');
        $this->addSql('DROP INDEX idx_processo_dados_manuais_por');
        $this->addSql('ALTER TABLE processo DROP classe_manual');
        $this->addSql('ALTER TABLE processo DROP assuntos_manual');
        $this->addSql('ALTER TABLE processo DROP jurisdicao_manual');
        $this->addSql('ALTER TABLE processo DROP orgao_manual');
        $this->addSql('ALTER TABLE processo DROP distribuicao_manual');
        $this->addSql('ALTER TABLE processo DROP valor_causa_manual');
        $this->addSql('ALTER TABLE processo DROP segredo_manual');
        $this->addSql('ALTER TABLE processo DROP digital_manual');
        $this->addSql('ALTER TABLE processo DROP gratuita_manual');
        $this->addSql('ALTER TABLE processo DROP tutela_manual');
        $this->addSql('ALTER TABLE processo DROP prioridade_manual');
        $this->addSql('ALTER TABLE processo DROP dados_manuais_em');
        $this->addSql('ALTER TABLE processo DROP dados_manuais_por_id');
    }
}
