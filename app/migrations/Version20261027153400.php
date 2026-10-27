<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nova meta, análise do prazo (desenho 02, spec meta-analise-do-prazo): a meta guarda o prazo jurídico, de onde ele
 * veio, as datas de publicação e de ciência informadas e se o prazo da meta foi o interno sugerido.
 * Tudo anulável (menos o booleano, falso por padrão), sem backfill: as metas antigas ficam sem análise.
 */
final class Version20261027153400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tarefa: prazo_juridico, estado_prazo_juridico, data_publicacao, data_ciencia e usou_prazo_sugerido';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa ADD prazo_juridico DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa ADD estado_prazo_juridico VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa ADD data_publicacao DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa ADD data_ciencia DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE tarefa ADD usou_prazo_sugerido BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa DROP prazo_juridico');
        $this->addSql('ALTER TABLE tarefa DROP estado_prazo_juridico');
        $this->addSql('ALTER TABLE tarefa DROP data_publicacao');
        $this->addSql('ALTER TABLE tarefa DROP data_ciencia');
        $this->addSql('ALTER TABLE tarefa DROP usou_prazo_sugerido');
    }
}
