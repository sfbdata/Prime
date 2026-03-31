<?php

namespace App\DataFixtures;

use App\Entity\Permission\Permission;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Popula o catálogo global de permissões (24 códigos aprovados — Dia 1).
 * Idempotente: usa upsert por código para não duplicar em re-execuções.
 */
class PermissionFixture extends Fixture
{
    private const PERMISSIONS = [
        // --- módulos ---
        ['code' => 'modules.pastas.view',       'description' => 'Acesso ao módulo Pastas',                     'group' => 'modules'],
        ['code' => 'modules.clientes.view',      'description' => 'Acesso ao módulo Clientes',                   'group' => 'modules'],
        ['code' => 'modules.processos.view',     'description' => 'Acesso ao módulo Processos',                  'group' => 'modules'],
        ['code' => 'modules.tarefas.view',       'description' => 'Acesso ao módulo Tarefas (visão do usuário)', 'group' => 'modules'],
        ['code' => 'modules.agenda.view',        'description' => 'Acesso ao módulo Agenda',                     'group' => 'modules'],
        ['code' => 'modules.servicedesk.view',   'description' => 'Acesso ao módulo Service Desk (usuário)',     'group' => 'modules'],
        ['code' => 'modules.precadastros.view',  'description' => 'Acesso ao módulo Pré-Cadastros',              'group' => 'modules'],
        ['code' => 'modules.ponto.view',         'description' => 'Acesso ao módulo Ponto Eletrônico',          'group' => 'modules'],
        ['code' => 'modules.financeiro.view',    'description' => 'Acesso ao módulo Financeiro (futuro)',        'group' => 'modules'],
        ['code' => 'modules.bi.view',            'description' => 'Acesso ao módulo BI (futuro)',                'group' => 'modules'],

        // --- recursos ---
        ['code' => 'resources.pasta.view',       'description' => 'Visualizar pasta específica',                 'group' => 'resources'],
        ['code' => 'resources.pasta.edit',       'description' => 'Criar e editar pasta',                        'group' => 'resources'],
        ['code' => 'resources.pasta.delete',     'description' => 'Excluir pasta',                               'group' => 'resources'],
        ['code' => 'resources.cliente.view',     'description' => 'Visualizar cliente específico',               'group' => 'resources'],
        ['code' => 'resources.cliente.edit',     'description' => 'Criar e editar cliente',                      'group' => 'resources'],
        ['code' => 'resources.cliente.delete',   'description' => 'Excluir cliente',                             'group' => 'resources'],
        ['code' => 'resources.processo.view',    'description' => 'Visualizar processo específico',              'group' => 'resources'],
        ['code' => 'resources.processo.edit',    'description' => 'Criar e editar processo',                     'group' => 'resources'],
        ['code' => 'resources.processo.delete',  'description' => 'Excluir processo',                            'group' => 'resources'],

        // --- admin ---
        ['code' => 'admin.roles.manage',               'description' => 'Criar/editar/excluir perfis do tenant',             'group' => 'admin'],
        ['code' => 'admin.users.manage',               'description' => 'Gerenciar funcionários (editar perfil, desativar)', 'group' => 'admin'],
        ['code' => 'admin.users.invite',               'description' => 'Convidar usuários para o tenant',                  'group' => 'admin'],
        ['code' => 'admin.access_requests.approve',    'description' => 'Aprovar/negar solicitações de acesso por item',     'group' => 'admin'],
        ['code' => 'admin.tenant.settings.manage',     'description' => 'Editar configurações do escritório',               'group' => 'admin'],
        ['code' => 'admin.tarefas.manage',             'description' => 'Gestão completa de tarefas (visão admin)',          'group' => 'admin'],
        ['code' => 'admin.servicedesk.manage',         'description' => 'Gestão de chamados Service Desk (TI)',              'group' => 'admin'],
        ['code' => 'admin.ponto.manage',               'description' => 'Gestão de Ponto (sedes, escalas, aprovações)',      'group' => 'admin'],
        ['code' => 'admin.audit.view',                 'description' => 'Acessar trilha de auditoria',                      'group' => 'admin'],
    ];

    public function load(ObjectManager $manager): void
    {
        $repo = $manager->getRepository(Permission::class);

        foreach (self::PERMISSIONS as $data) {
            $permission = $repo->findOneBy(['code' => $data['code']]);

            if ($permission === null) {
                $permission = new Permission();
                $permission->setCode($data['code']);
            }

            $permission->setDescription($data['description']);
            $permission->setGroup($data['group']);

            $manager->persist($permission);
            $this->addReference('permission.' . $data['code'], $permission);
        }

        $manager->flush();
    }
}
