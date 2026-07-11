<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cobranças — data-migration do catálogo de PERMISSÕES para PRODUÇÃO.
 *
 * Os 4 códigos de permissão do módulo Gestão de Cobranças existiam apenas no `PermissionFixture`
 * (dev/test); fixtures NÃO rodam em produção, então sem esta migration o catálogo de prod não teria os
 * códigos e o menu/rotas de Cobranças ficariam inacessíveis a papéis não-`isSystem`. Espelha o padrão do
 * DJEN (`Version20260706195821`): insere no catálogo de forma IDEMPOTENTE (`WHERE NOT EXISTS`, seguro em
 * re-execução e em bancos já seedados), SEM auto-conceder a papéis — a concessão a cada `TenantRole` é
 * feita depois pelo admin na UI de papéis, por escritório (super-admin já enxerga por bypass `isSystem`).
 *
 * As descrições e os grupos são idênticos aos do `PermissionFixture` (única fonte até aqui). SQL LITERAL
 * (sem parâmetros vinculados), igual à migration irmã do DJEN: uma migration de produção não deve depender
 * do reescritor de placeholders do PDO nem do driver configurado. `"group"` é palavra reservada no Postgres
 * → vem entre aspas. Nenhuma descrição contém aspa simples, então o literal é seguro.
 */
final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cobranças — insere no catálogo de permissões (idempotente) os 4 códigos do módulo '
            . '(modules.cobrancas.view, resources.cobranca.gerenciar, resources.carteira.gerenciar, '
            . 'resources.cobranca.movimentacao_financeira) para produção. Sem conceder a papéis.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO permission (code, description, "group")
            SELECT 'modules.cobrancas.view', 'Acesso ao módulo Gestão de Cobranças', 'modules'
            WHERE NOT EXISTS (SELECT 1 FROM permission WHERE code = 'modules.cobrancas.view')
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO permission (code, description, "group")
            SELECT 'resources.cobranca.gerenciar', 'Gerenciar casos de cobrança (operar obrigações, contatos, ações)', 'resources'
            WHERE NOT EXISTS (SELECT 1 FROM permission WHERE code = 'resources.cobranca.gerenciar')
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO permission (code, description, "group")
            SELECT 'resources.carteira.gerenciar', 'Gerenciar carteiras de cobrança e suas configurações', 'resources'
            WHERE NOT EXISTS (SELECT 1 FROM permission WHERE code = 'resources.carteira.gerenciar')
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO permission (code, description, "group")
            SELECT 'resources.cobranca.movimentacao_financeira', 'Registrar ou alterar movimentações financeiras da cobrança', 'resources'
            WHERE NOT EXISTS (SELECT 1 FROM permission WHERE code = 'resources.cobranca.movimentacao_financeira')
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Defensivo e reversível: remove primeiro as concessões a papéis (FK sem ON DELETE CASCADE),
        // depois o catálogo — a ordem inversa violaria a FK tenant_role_permission → permission.
        $this->addSql(<<<'SQL'
            DELETE FROM tenant_role_permission
            WHERE permission_id IN (
                SELECT id FROM permission WHERE code IN (
                    'modules.cobrancas.view',
                    'resources.cobranca.gerenciar',
                    'resources.carteira.gerenciar',
                    'resources.cobranca.movimentacao_financeira'
                )
            )
        SQL);
        $this->addSql(<<<'SQL'
            DELETE FROM permission WHERE code IN (
                'modules.cobrancas.view',
                'resources.cobranca.gerenciar',
                'resources.carteira.gerenciar',
                'resources.cobranca.movimentacao_financeira'
            )
        SQL);
    }
}
