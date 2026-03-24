<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260324200915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE processo DROP doc_peca_ok');
        $this->addSql('ALTER TABLE processo DROP doc_procuracao_ok');
        $this->addSql('ALTER TABLE processo DROP doc_identificacao_ok');
        $this->addSql('ALTER TABLE processo DROP doc_comprovante_residencia_ok');
        $this->addSql('ALTER TABLE processo DROP doc_gratuidade_justica_ok');
        $this->addSql('ALTER TABLE processo DROP doc_demais_ok');
        $this->addSql('ALTER TABLE processo DROP status_documentos');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE processo ADD doc_peca_ok BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE processo ADD doc_procuracao_ok BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE processo ADD doc_identificacao_ok BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE processo ADD doc_comprovante_residencia_ok BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE processo ADD doc_gratuidade_justica_ok BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE processo ADD doc_demais_ok BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE processo ADD status_documentos VARCHAR(40) NOT NULL');
    }
}
