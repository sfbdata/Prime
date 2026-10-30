<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Permissão do módulo Cálculo (atualização monetária). Só INSERT, idempotente: cria `modules.calculo.view` e a
 * concede aos perfis que já têm `modules.pastas.view` (padrão decidido na spec calculo-atualizacao-monetaria).
 * Perfis de sistema passam por bypass; os demais ganham a permissão no painel de Perfis.
 */
final class Version20261030110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Permissão modules.calculo.view, concedida aos perfis que já veem Pastas';
    }

    public function up(Schema $schema): void
    {
        // "group" é palavra reservada no Postgres, por isso vem entre aspas.
        $this->addSql(<<<'SQL'
            INSERT INTO permission (code, description, "group")
            SELECT 'modules.calculo.view', 'Acesso ao módulo Cálculo (atualização monetária)', 'modules'
            WHERE NOT EXISTS (SELECT 1 FROM permission WHERE code = 'modules.calculo.view')
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO tenant_role_permission (tenant_role_id, permission_id, granted_at)
            SELECT trp.tenant_role_id, nova.id, NOW()
            FROM tenant_role_permission trp
            JOIN permission pastas ON pastas.id = trp.permission_id AND pastas.code = 'modules.pastas.view'
            CROSS JOIN (SELECT id FROM permission WHERE code = 'modules.calculo.view') nova
            WHERE NOT EXISTS (
                SELECT 1 FROM tenant_role_permission x
                WHERE x.tenant_role_id = trp.tenant_role_id AND x.permission_id = nova.id
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM tenant_role_permission WHERE permission_id = (SELECT id FROM permission WHERE code = 'modules.calculo.view')");
        $this->addSql("DELETE FROM permission WHERE code = 'modules.calculo.view'");
    }
}
