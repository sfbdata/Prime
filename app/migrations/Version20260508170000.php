<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Criar usuário dedicado para testes E2E (e2e@jusprime.local), só em banco de teste/e2e';
    }

    /**
     * Trava da auditoria 4.07. A antiga olhava o APP_ENV pelo getenv, que não enxerga o APP_ENV vindo só do .env
     * (o Dotenv do Symfony não faz putenv): um banco com dado real migrado assim ganhava um Master de senha conhecida.
     * Agora só semeia quando o banco é de teste/e2e pelo nome (termina em _test ou _e2e, com o número do paratest
     * opcional) ou quando E2E_SEMEAR_USUARIO=1 é variável REAL do processo (getenv não vê o .env).
     */
    public static function podeSemearUsuarioE2e(string $nomeDoBanco, string|false $semear): bool
    {
        return $semear === '1' || preg_match('/_(test|e2e)\d*$/', $nomeDoBanco) === 1;
    }

    private function podeSemear(): bool
    {
        return self::podeSemearUsuarioE2e((string) $this->connection->getDatabase(), getenv('E2E_SEMEAR_USUARIO'));
    }

    public function up(Schema $schema): void
    {
        if (!$this->podeSemear()) {
            return;
        }

        // Hash BCrypt calculado via Docker no momento da criação. A senha fica com quem roda o e2e (E2E_USER_PASSWORD).
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
        if (!$this->podeSemear()) {
            return;
        }

        // user_profiles e user_tenant são removidos por CASCADE
        $this->addSql("DELETE FROM \"user\" WHERE email = 'e2e@jusprime.local'");
    }
}
