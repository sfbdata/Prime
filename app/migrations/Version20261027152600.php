<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Push Processual (desenho 11, spec push-prazo-na-publicacao): a publicação guarda o prazo em dias úteis
 * que o usuário escolheu no modal, quando o prazo foi cumprido e a meta criada a partir dele.
 * Tudo anulável, sem backfill.
 */
final class Version20261027152600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Push: publicacao_djen ganha prazo_dias, prazo_cumprido_em e tarefa_id (meta criada)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publicacao_djen ADD prazo_dias SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE publicacao_djen ADD prazo_cumprido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE publicacao_djen ADD tarefa_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE publicacao_djen ADD CONSTRAINT FK_D525305578217710 FOREIGN KEY (tarefa_id) REFERENCES tarefa (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D525305578217710 ON publicacao_djen (tarefa_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publicacao_djen DROP CONSTRAINT FK_D525305578217710');
        $this->addSql('DROP INDEX IDX_D525305578217710');
        $this->addSql('ALTER TABLE publicacao_djen DROP prazo_dias');
        $this->addSql('ALTER TABLE publicacao_djen DROP prazo_cumprido_em');
        $this->addSql('ALTER TABLE publicacao_djen DROP tarefa_id');
    }
}
