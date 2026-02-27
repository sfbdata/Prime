<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260227173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona documentos e checklist de documentação em processos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processo ADD doc_peca_ok BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE processo ADD doc_procuracao_ok BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE processo ADD doc_identificacao_ok BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE processo ADD doc_comprovante_residencia_ok BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE processo ADD doc_gratuidade_justica_ok BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE processo ADD doc_demais_ok BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("ALTER TABLE processo ADD status_documentos VARCHAR(40) DEFAULT 'PENDENTE_DE_DOCUMENTACAO' NOT NULL");

        $this->addSql('CREATE TABLE documento_processo (id SERIAL NOT NULL, processo_id INT NOT NULL, tipo VARCHAR(40) NOT NULL, nome_original VARCHAR(255) NOT NULL, caminho_arquivo VARCHAR(255) NOT NULL, mime_type VARCHAR(100) DEFAULT NULL, tamanho INT DEFAULT NULL, criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_1418D2E13474F1A ON documento_processo (processo_id)');
        $this->addSql('ALTER TABLE documento_processo ADD CONSTRAINT FK_1418D2E13474F1A FOREIGN KEY (processo_id) REFERENCES processo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documento_processo DROP CONSTRAINT FK_1418D2E13474F1A');
        $this->addSql('DROP TABLE documento_processo');

        $this->addSql('ALTER TABLE processo DROP doc_peca_ok');
        $this->addSql('ALTER TABLE processo DROP doc_procuracao_ok');
        $this->addSql('ALTER TABLE processo DROP doc_identificacao_ok');
        $this->addSql('ALTER TABLE processo DROP doc_comprovante_residencia_ok');
        $this->addSql('ALTER TABLE processo DROP doc_gratuidade_justica_ok');
        $this->addSql('ALTER TABLE processo DROP doc_demais_ok');
        $this->addSql('ALTER TABLE processo DROP status_documentos');
    }
}
