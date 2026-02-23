<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223114000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Etapa 2: sincroniza fallback textual processo_pai e adiciona check contra auto-referencia direta';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE processo filho
            SET processo_pai = pai.numero_processo
            FROM processo pai
            WHERE filho.processo_pai_id = pai.id
              AND (filho.processo_pai IS NULL OR filho.processo_pai <> pai.numero_processo)
            SQL
        );

        $this->addSql('ALTER TABLE processo ADD CONSTRAINT CHK_PROCESSO_PAI_SELF CHECK (processo_pai_id IS NULL OR processo_pai_id <> id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processo DROP CONSTRAINT CHK_PROCESSO_PAI_SELF');
    }
}
