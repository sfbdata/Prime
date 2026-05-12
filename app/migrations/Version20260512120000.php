<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed smoke test: Escritório Smoke B com Emily (user 3) no tenant B — ignorada em produção';
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
            'SELECT id FROM tenant WHERE name = :name',
            ['name' => 'Escritório Smoke B']
        );
        if ($check->fetchOne() !== false) {
            return;
        }

        $tenantResult = $this->connection->executeQuery(
            'INSERT INTO tenant (name, is_active, created_at, criado_por_id)'
            . ' VALUES (:name, TRUE, NOW(), :criado_por_id) RETURNING id',
            ['name' => 'Escritório Smoke B', 'criado_por_id' => 2]
        );
        $tenantId = (int) $tenantResult->fetchOne();

        $roleResult = $this->connection->executeQuery(
            'INSERT INTO tenant_role (name, description, is_system, created_at, tenant_id)'
            . ' VALUES (:name, :description, FALSE, NOW(), :tid) RETURNING id',
            ['name' => 'Admin', 'description' => 'Admin do Escritório Smoke B', 'tid' => $tenantId]
        );
        $roleId = (int) $roleResult->fetchOne();

        $this->connection->executeStatement(
            'INSERT INTO tenant_role_permission (tenant_role_id, permission_id, granted_at)'
            . ' VALUES (:rid, 21, NOW())',
            ['rid' => $roleId]
        );

        $this->connection->executeStatement(
            'INSERT INTO tenant_role_permission (tenant_role_id, permission_id, granted_at)'
            . ' VALUES (:rid, 27, NOW())',
            ['rid' => $roleId]
        );

        $this->connection->executeStatement(
            'INSERT INTO user_tenant (user_id, tenant_id, tenant_role_id, is_active, created_at)'
            . ' VALUES (3, :tid, :rid, TRUE, NOW())',
            ['tid' => $tenantId, 'rid' => $roleId]
        );
    }

    public function down(Schema $schema): void
    {
        $result = $this->connection->executeQuery(
            'SELECT id FROM tenant WHERE name = :name',
            ['name' => 'Escritório Smoke B']
        );
        $tenantId = $result->fetchOne();

        if ($tenantId === false) {
            return;
        }

        $tenantId = (int) $tenantId;

        $this->connection->executeStatement(
            'DELETE FROM tenant_role_permission'
            . ' WHERE tenant_role_id IN (SELECT id FROM tenant_role WHERE tenant_id = :tid)',
            ['tid' => $tenantId]
        );

        $this->connection->executeStatement(
            'DELETE FROM tenant_role WHERE tenant_id = :tid',
            ['tid' => $tenantId]
        );

        // user_tenant cai por ON DELETE CASCADE em tenant_id
        $this->connection->executeStatement(
            'DELETE FROM tenant WHERE id = :tid',
            ['tid' => $tenantId]
        );
    }
}