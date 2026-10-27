<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Destino por peça no Peticionar (desenho 07, F7): `pasta_documento.processo_destino_id` aponta o
 * vínculo (principal ou apensado) a que a peça se destina. Nulo = o principal. Apagar o vínculo
 * zera o destino.
 */
final class Version20261027154400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasta: processo de destino por peça (pasta_documento.processo_destino_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_documento ADD processo_destino_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_documento ADD CONSTRAINT FK_PASTA_DOC_PROC_DESTINO FOREIGN KEY (processo_destino_id) REFERENCES pasta_processo (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PASTA_DOC_PROC_DESTINO ON pasta_documento (processo_destino_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_documento DROP CONSTRAINT FK_PASTA_DOC_PROC_DESTINO');
        $this->addSql('DROP INDEX IDX_PASTA_DOC_PROC_DESTINO');
        $this->addSql('ALTER TABLE pasta_documento DROP processo_destino_id');
    }
}
