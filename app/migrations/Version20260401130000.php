<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed do catálogo global de permissões (idempotente)';
    }

    public function up(Schema $schema): void
    {
        $permissions = [
            // módulos
            ['modules.pastas.view',       'Acesso ao módulo Pastas',                     'modules'],
            ['modules.clientes.view',      'Acesso ao módulo Clientes',                   'modules'],
            ['modules.processos.view',     'Acesso ao módulo Processos',                  'modules'],
            ['modules.tarefas.view',       'Acesso ao módulo Tarefas (visão do usuário)', 'modules'],
            ['modules.agenda.view',        'Acesso ao módulo Agenda',                     'modules'],
            ['modules.servicedesk.view',   'Acesso ao módulo Service Desk (usuário)',     'modules'],
            ['modules.precadastros.view',  'Acesso ao módulo Pré-Cadastros',              'modules'],
            ['modules.ponto.view',         'Acesso ao módulo Ponto Eletrônico',           'modules'],
            ['modules.financeiro.view',    'Acesso ao módulo Financeiro (futuro)',         'modules'],
            ['modules.bi.view',            'Acesso ao módulo BI (futuro)',                 'modules'],

            // recursos
            ['resources.pasta.view',       'Visualizar pasta específica',                 'resources'],
            ['resources.pasta.edit',       'Criar e editar pasta',                        'resources'],
            ['resources.pasta.delete',     'Excluir pasta',                               'resources'],
            ['resources.cliente.view',     'Visualizar cliente específico',               'resources'],
            ['resources.cliente.edit',     'Criar e editar cliente',                      'resources'],
            ['resources.cliente.delete',   'Excluir cliente',                             'resources'],
            ['resources.processo.view',    'Visualizar processo específico',              'resources'],
            ['resources.processo.edit',    'Criar e editar processo',                     'resources'],
            ['resources.processo.delete',  'Excluir processo',                            'resources'],

            // admin
            ['admin.roles.manage',               'Criar/editar/excluir perfis do tenant',             'admin'],
            ['admin.users.manage',               'Gerenciar funcionários (editar perfil, desativar)', 'admin'],
            ['admin.users.invite',               'Convidar usuários para o tenant',                   'admin'],
            ['admin.access_requests.approve',    'Aprovar/negar solicitações de acesso por item',     'admin'],
            ['admin.tenant.settings.manage',     'Editar configurações do escritório',                'admin'],
            ['admin.tarefas.manage',             'Gestão completa de tarefas (visão admin)',          'admin'],
            ['admin.servicedesk.manage',         'Gestão de chamados Service Desk (TI)',              'admin'],
            ['admin.ponto.manage',               'Gestão de Ponto (sedes, escalas, aprovações)',      'admin'],
            ['admin.audit.view',                 'Acessar trilha de auditoria',                       'admin'],
        ];

        foreach ($permissions as [$code, $description, $group]) {
            $this->addSql(
                'INSERT INTO permission (code, description, "group") VALUES (:code, :description, :group) ON CONFLICT (code) DO UPDATE SET description = EXCLUDED.description, "group" = EXCLUDED."group"',
                ['code' => $code, 'description' => $description, 'group' => $group]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM permission WHERE code LIKE 'modules.%' OR code LIKE 'resources.%' OR code LIKE 'admin.%'");
    }
}
