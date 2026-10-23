<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agenda (spec `docs/specs/agenda-prazos-permissao.md`): a permissão de perfil "Ver prazos na agenda"
 * e a concessão que a faz nascer ligada (decisão do dono, 20/10/2026).
 *
 * Primeira migration que concede permissão a perfis existentes: recebe a nova todo perfil que hoje
 * tem `modules.pastas.view` (antes, ver as pastas era o que trazia os prazos à Agenda) e todo perfil
 * de sistema, que já veria pelo bypass e fica com a linha como o `TenantBootstrapService` faz com
 * escritório novo. Idempotente: o código só entra se faltar e a concessão ignora a que já existe.
 * O texto do código, da descrição e do grupo é o mesmo da `PermissionFixture` (teste amarra).
 */
final class Version20261020134100 extends AbstractMigration
{
    public const CODIGO = 'resources.agenda.ver_prazos';
    public const DESCRICAO = 'Ver prazos na agenda';
    public const GRUPO = 'resources';

    public function getDescription(): string
    {
        return 'Agenda: permissão resources.agenda.ver_prazos, concedida a quem vê pastas e aos perfis de sistema';
    }

    /** O código, se faltar. */
    public const SQL_PERMISSAO = 'INSERT INTO permission (code, description, "group") SELECT :codigo, :descricao, :grupo
        WHERE NOT EXISTS (SELECT 1 FROM permission WHERE code = :codigo)';

    /** A concessão a quem vê pastas e aos perfis de sistema, sem repetir a que já existe. */
    public const SQL_CONCESSAO = "INSERT INTO tenant_role_permission (tenant_role_id, permission_id, granted_at)
        SELECT tr.id, nova.id, NOW() AT TIME ZONE 'America/Sao_Paulo'
          FROM tenant_role tr
          JOIN permission nova ON nova.code = :codigo
         WHERE tr.is_system
            OR EXISTS (SELECT 1 FROM tenant_role_permission trp
                         JOIN permission p ON p.id = trp.permission_id
                        WHERE trp.tenant_role_id = tr.id AND p.code = 'modules.pastas.view')
        ON CONFLICT (tenant_role_id, permission_id) DO NOTHING";

    public function up(Schema $schema): void
    {
        $this->addSql(self::SQL_PERMISSAO, ['codigo' => self::CODIGO, 'descricao' => self::DESCRICAO, 'grupo' => self::GRUPO]);
        $this->addSql(self::SQL_CONCESSAO, ['codigo' => self::CODIGO]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DELETE FROM tenant_role_permission WHERE permission_id IN (SELECT id FROM permission WHERE code = :codigo)',
            ['codigo' => self::CODIGO],
        );
        $this->addSql('DELETE FROM permission WHERE code = :codigo', ['codigo' => self::CODIGO]);
    }
}
