<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Etapa 1: adiciona auto-relacionamento processo_pai_id com backfill a partir de processo_pai (texto)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processo ADD processo_pai_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_PROCESSO_PAI_ID ON processo (processo_pai_id)');
        $this->addSql('ALTER TABLE processo ADD CONSTRAINT FK_PROCESSO_PAI_ID FOREIGN KEY (processo_pai_id) REFERENCES processo (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            UPDATE processo filho
            SET processo_pai_id = pai.id
            FROM processo pai
            WHERE filho.processo_pai IS NOT NULL
              AND filho.processo_pai_id IS NULL
              AND filho.processo_pai = pai.numero_processo
              AND filho.id <> pai.id
            SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processo DROP CONSTRAINT FK_PROCESSO_PAI_ID');
        $this->addSql('DROP INDEX IDX_PROCESSO_PAI_ID');
        $this->addSql('ALTER TABLE processo DROP COLUMN processo_pai_id');
    }
}
