<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Criar usuário dedicado para testes E2E (e2e@jusprime.local) — no-op em produção';
    }

    public function up(Schema $schema): void
    {
        if (getenv('APP_ENV') === 'prod') {
            return;
        }

        // Senha: e2e_local_placeholder (hash BCrypt calculado via Docker no momento da criação)
        $this->addSql(<<<'SQL'
            INSERT INTO "user" (email, password, roles, full_name, is_active, created_at)
            SELECT
                'e2e@jusprime.local',
                '$2y$10$sKlSNzTyifKhUefFtWYZhujXhQnUlTYbdB0ecEjU/NIxXDYmtno9m',
                '["ROLE_USER","ROLE_SUPER_ADMIN"]',
                'E2E Test User',
                true,
                NOW()
            WHERE NOT EXISTS (
                SELECT 1 FROM "user" WHERE email = 'e2e@jusprime.local'
            )
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO user_profiles (user_id, criado_em)
            SELECT u.id, NOW()
            FROM "user" u
            WHERE u.email = 'e2e@jusprime.local'
              AND NOT EXISTS (
                  SELECT 1 FROM user_profiles up2 WHERE up2.user_id = u.id
              )
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO user_tenant (user_id, tenant_id, is_active, created_at)
            SELECT u.id, 1, true, NOW()
            FROM "user" u
            WHERE u.email = 'e2e@jusprime.local'
              AND NOT EXISTS (
                  SELECT 1 FROM user_tenant ut2 WHERE ut2.user_id = u.id AND ut2.tenant_id = 1
              )
              -- Banco NOVO (criado só por migrations) não tem tenant nenhum, e o vínculo
              -- violava a FK — a migration só passava onde já havia dump carregado. Sem esta
              -- guarda não é possível criar um banco de teste do zero, que é o que o
              -- isolamento por frente (TEST_TOKEN) e o ritual de migrations exigem.
              AND EXISTS (SELECT 1 FROM tenant WHERE id = 1)
        SQL);

    }

    public function down(Schema $schema): void
    {
        if (getenv('APP_ENV') === 'prod') {
            return;
        }

        // user_profiles e user_tenant são removidos por CASCADE
        $this->addSql("DELETE FROM \"user\" WHERE email = 'e2e@jusprime.local'");
    }
}
