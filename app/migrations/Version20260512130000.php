<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed smoke test: resource_access fake para Emily (user 3) — ignorada em produção';
    }

    public function preUp(Schema $schema): void
    {
        $this->skipIf(($_ENV['APP_ENV'] ?? 'dev') === 'prod', 'Seed de smoke test — ignorada em produção');
    }

    public function preDown(Schema $schema): void
    {
        $this->skipIf(($_ENV['APP_ENV'] ?? 'dev') === 'prod', 'Rollback de seed de smoke test — ignorado em produção');
    }

    public function up(Schema $schema): void
    {
        $check = $this->connection->executeQuery(
            'SELECT id FROM resource_access WHERE user_id = 3 AND resource_type = :rt AND resource_id = 999999 LIMIT 1',
            ['rt' => 'pasta']
        );
        if ($check->fetchOne() !== false) {
            return;
        }

        $result = $this->connection->executeQuery(
            'INSERT INTO resource_access (user_id, resource_type, resource_id, can_view, can_edit, can_delete, granted_at, granted_by_id)'
            . ' VALUES (3, :rt, 999999, TRUE, FALSE, FALSE, NOW(), 2) RETURNING id',
            ['rt' => 'pasta']
        );
        $id = (int) $result->fetchOne();

        $this->write("Resource access fake criado com id=$id");
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement(
            'DELETE FROM resource_access WHERE user_id = 3 AND resource_type = :rt AND resource_id = 999999',
            ['rt' => 'pasta']
        );
    }
}