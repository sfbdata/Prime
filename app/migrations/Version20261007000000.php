<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M4 da aba Documentos (D8, DOC-73): estado do checklist de documentação na pasta.
 *
 * Escrita à mão, só com as três colunas desta frente (nenhum `DROP INDEX` de índice funcional nem
 * ruído de outra frente). Nomes de FK e índice no padrão do DBAL (`FK_`/`IDX_` + crc32 da tabela e
 * da coluna) — `9B3BBC81` é o crc32 de `pasta`, o mesmo prefixo do precedente `excluida_por_id`
 * (Version20260829130654) —, para o `doctrine:schema:validate` não propor nada.
 *
 * Nada de dado existente é tocado: as três nascem nulas, e nulo é "checklist ativo" — o estado de
 * hoje de todas as pastas.
 *
 * `ON DELETE SET NULL` em quem desativou: apagar o usuário não pode reativar o checklist nem
 * apagar a pasta.
 */
final class Version20261007000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona checklist_desativado_em, checklist_desativado_por_id e checklist_motivo em pasta (aba Documentos, D8)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta ADD checklist_desativado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta ADD checklist_desativado_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta ADD checklist_motivo VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta ADD CONSTRAINT FK_9B3BBC81D7D34AB0 FOREIGN KEY (checklist_desativado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9B3BBC81D7D34AB0 ON pasta (checklist_desativado_por_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta DROP CONSTRAINT FK_9B3BBC81D7D34AB0');
        $this->addSql('DROP INDEX IDX_9B3BBC81D7D34AB0');
        $this->addSql('ALTER TABLE pasta DROP checklist_desativado_em');
        $this->addSql('ALTER TABLE pasta DROP checklist_desativado_por_id');
        $this->addSql('ALTER TABLE pasta DROP checklist_motivo');
    }
}
