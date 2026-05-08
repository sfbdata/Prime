<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migra vínculos user↔tenant para a tabela user_tenant (etapa 2/6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            INSERT INTO user_tenant (
                user_id,
                tenant_id,
                tenant_role_id,
                cargo_id,
                lotacao_id,
                codigo_funcionario,
                data_admissao,
                demitido_em,
                last_login_at,
                is_active,
                created_at,
                updated_at
            )
            SELECT
                u.id,
                u.tenant_id,
                u.tenant_role_id,
                u.cargo_id,
                u.lotacao_id,
                u.codigo_funcionario,
                up.data_admissao,
                u.demitido_em,
                u.last_login,
                CASE
                    WHEN u.is_active = true AND u.demitido_em IS NULL THEN true
                    ELSE false
                END,
                u.created_at,
                NULL
            FROM \"user\" u
            LEFT JOIN user_profiles up ON up.user_id = u.id
            WHERE u.tenant_id IS NOT NULL
            ON CONFLICT (user_id, tenant_id) DO NOTHING
        ");
    }

    public function down(Schema $schema): void
    {
        // Seguro: user_tenant ainda não é lida por nenhum código em produção (etapa 2/6)
        $this->addSql('DELETE FROM user_tenant');
    }
}
