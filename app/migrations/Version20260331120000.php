<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Substitui unique constraint em access_request por partial index apenas para status=pending';
    }

    public function up(Schema $schema): void
    {
        // Remove o constraint antigo que incluía status (causava erro ao re-aprovar)
        $this->addSql('ALTER TABLE access_request DROP CONSTRAINT IF EXISTS uniq_access_request_pending');

        // Remove índice antigo se existir com nome alternativo
        $this->addSql('DROP INDEX IF EXISTS uniq_access_request_pending');

        // Cria partial index: unicidade de (user_id, resource_type, resource_id, action) apenas para pending
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_access_request_pending ON access_request (user_id, resource_type, resource_id, action) WHERE status = \'pending\''
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_access_request_pending');

        $this->addSql(
            'ALTER TABLE access_request ADD CONSTRAINT uniq_access_request_pending UNIQUE (user_id, resource_type, resource_id, action, status)'
        );
    }
}
