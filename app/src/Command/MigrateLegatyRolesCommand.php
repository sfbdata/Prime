<?php

namespace App\Command;

use App\Entity\Auth\User;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dia 14 — Migração de dados legados.
 *
 * Mapeia usuários que ainda dependem de ROLE_ADMIN / ROLE_USER legados
 * para os perfis TenantRole do novo modelo de permissões.
 *
 * Regras de mapeamento:
 *  - ROLE_SUPER_ADMIN → ignorado (papel global de plataforma, sem tenant)
 *  - ROLE_ADMIN       → perfil "Administrador do Escritório" do tenant (isSystem=true)
 *  - ROLE_USER        → perfil "Advogado" do tenant (criado se não existir)
 *  - Usuários sem tenant → ignorados com aviso
 *  - Usuários que já têm tenantRole → ignorados (idempotente)
 *
 * O comando é idempotente: pode ser executado várias vezes com segurança.
 *
 * Uso:
 *   docker compose exec -w /var/www/app php php bin/console app:migrate-legacy-roles
 *   docker compose exec -w /var/www/app php php bin/console app:migrate-legacy-roles --dry-run
 */
#[AsCommand(
    name: 'app:migrate-legacy-roles',
    description: 'Migra usuários legados (ROLE_ADMIN/ROLE_USER) para TenantRole do novo modelo',
)]
class MigrateLegatyRolesCommand extends Command
{
    /**
     * Permissões do perfil "Advogado" conforme catálogo (Dia 1).
     * Espelha a definição em permissions-catalog.md §3.
     */
    private const ADVOGADO_PERMISSIONS = [
        'modules.pastas.view',
        'modules.clientes.view',
        'modules.processos.view',
        'modules.tarefas.view',
        'modules.agenda.view',
        'resources.pasta.view',
        'resources.pasta.edit',
        'resources.pasta.delete',
        'resources.cliente.view',
        'resources.cliente.edit',
        'resources.cliente.delete',
        'resources.processo.view',
        'resources.processo.edit',
        'resources.processo.delete',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Simula a migração sem persistir alterações no banco'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Migração de Dados Legados — Dia 14');

        if ($dryRun) {
            $io->note('Modo DRY-RUN: nenhuma alteração será salva.');
        }

        // Carrega o catálogo de permissões uma única vez
        $permissionMap = $this->loadPermissionMap();

        if (empty($permissionMap)) {
            $io->error('Catálogo de permissões vazio. Execute as fixtures antes: app:fixtures:load ou doctrine:fixtures:load.');
            return Command::FAILURE;
        }

        // Busca todos os tenants ativos
        /** @var Tenant[] $tenants */
        $tenants = $this->entityManager->getRepository(Tenant::class)->findBy(['isActive' => true]);

        if (empty($tenants)) {
            $io->warning('Nenhum tenant ativo encontrado. Nada a migrar.');
            return Command::SUCCESS;
        }

        $totalMigrated   = 0;
        $totalSkipped    = 0;
        $totalNoTenant   = 0;
        $totalSuperAdmin = 0;

        // Processa cada tenant em isolamento
        foreach ($tenants as $tenant) {
            $io->section(sprintf('Tenant #%d — %s', $tenant->getId(), $tenant->getName()));

            // Garante perfil admin do tenant (idempotente via findOrCreate)
            $adminRole = $this->findOrCreateAdminRole($tenant, $permissionMap, $dryRun, $io);

            // Garante perfil Advogado do tenant
            $advogadoRole = $this->findOrCreateAdvogadoRole($tenant, $permissionMap, $dryRun, $io);

            // Carrega usuários deste tenant
            /** @var User[] $users */
            $users = $this->entityManager
                ->getRepository(User::class)
                ->findBy(['tenant' => $tenant]);

            foreach ($users as $user) {
                $result = $this->migrateUser($user, $adminRole, $advogadoRole, $dryRun, $io);

                match ($result) {
                    'migrated'    => $totalMigrated++,
                    'skipped'     => $totalSkipped++,
                    'super_admin' => $totalSuperAdmin++,
                    default       => null,
                };
            }
        }

        // Usuários sem tenant (ex.: super-admins globais)
        $usersWithoutTenant = $this->entityManager
            ->getRepository(User::class)
            ->findBy(['tenant' => null]);

        foreach ($usersWithoutTenant as $user) {
            $roles = $user->getRoles();

            if (in_array('ROLE_SUPER_ADMIN', $roles, true)) {
                $io->text(sprintf(
                    '  [IGNORADO] <fg=cyan>%s</> — ROLE_SUPER_ADMIN (papel global, sem tenant)',
                    $user->getEmail()
                ));
                $totalSuperAdmin++;
            } else {
                $io->warning(sprintf(
                    'Usuário %s não tem tenant e não é SUPER_ADMIN — ignorado.',
                    $user->getEmail()
                ));
                $totalNoTenant++;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            $io->success('flush() executado — alterações persistidas.');
        }

        $io->table(
            ['Status', 'Quantidade'],
            [
                ['Migrados',              $totalMigrated],
                ['Já tinham TenantRole',  $totalSkipped],
                ['SUPER_ADMIN (global)',   $totalSuperAdmin],
                ['Sem tenant (ignorados)', $totalNoTenant],
            ]
        );

        if ($dryRun) {
            $io->note('DRY-RUN concluído. Execute sem --dry-run para aplicar.');
        } else {
            $io->success(sprintf('Migração concluída. %d usuário(s) migrado(s).', $totalMigrated));
        }

        return Command::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Processa um único usuário.
     * Retorna: 'migrated' | 'skipped' | 'super_admin'
     */
    private function migrateUser(
        User $user,
        TenantRole $adminRole,
        TenantRole $advogadoRole,
        bool $dryRun,
        SymfonyStyle $io,
    ): string {
        $roles = $user->getRoles();

        // Papel global — não gerenciado por tenant
        if (in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            $io->text(sprintf(
                '  [IGNORADO] <fg=cyan>%s</> — ROLE_SUPER_ADMIN (papel global)',
                $user->getEmail()
            ));
            return 'super_admin';
        }

        // Já migrado — idempotente
        if ($user->getTenantRole() !== null) {
            $io->text(sprintf(
                '  [OK] <fg=green>%s</> — já tem perfil "%s"',
                $user->getEmail(),
                $user->getTenantRole()->getName()
            ));
            return 'skipped';
        }

        // Determina perfil alvo pela role legada
        if (in_array('ROLE_ADMIN', $roles, true)) {
            $targetRole  = $adminRole;
            $legacyLabel = 'ROLE_ADMIN';
        } else {
            // ROLE_USER (ou qualquer outro legado sem ROLE_ADMIN) → Advogado
            $targetRole  = $advogadoRole;
            $legacyLabel = 'ROLE_USER';
        }

        $io->text(sprintf(
            '  [MIGRAR] <fg=yellow>%s</> — %s → perfil "%s"',
            $user->getEmail(),
            $legacyLabel,
            $targetRole->getName()
        ));

        if (!$dryRun) {
            $user->setTenantRole($targetRole);
            $this->entityManager->persist($user);
        }

        return 'migrated';
    }

    /**
     * Retorna o perfil "Administrador do Escritório" do tenant.
     * Cria-o (com todas as permissões) se não existir.
     */
    private function findOrCreateAdminRole(
        Tenant $tenant,
        array $permissionMap,
        bool $dryRun,
        SymfonyStyle $io,
    ): TenantRole {
        $existing = $this->entityManager
            ->getRepository(TenantRole::class)
            ->findOneBy(['tenant' => $tenant, 'name' => 'Administrador do Escritório']);

        if ($existing !== null) {
            $io->text('  Perfil "Administrador do Escritório" já existe.');
            return $existing;
        }

        $io->text('  Criando perfil "Administrador do Escritório"...');

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador do Escritório');
        $role->setDescription('Perfil padrão com acesso total ao escritório. Não pode ser excluído.');
        $role->setIsSystem(true);

        if (!$dryRun) {
            $this->entityManager->persist($role);
            // Anexa todas as permissões do catálogo
            foreach ($permissionMap as $permission) {
                $trp = new TenantRolePermission();
                $trp->setTenantRole($role);
                $trp->setPermission($permission);
                $this->entityManager->persist($trp);
            }
        }

        return $role;
    }

    /**
     * Retorna o perfil "Advogado" do tenant.
     * Cria-o com as permissões definidas em ADVOGADO_PERMISSIONS se não existir.
     */
    private function findOrCreateAdvogadoRole(
        Tenant $tenant,
        array $permissionMap,
        bool $dryRun,
        SymfonyStyle $io,
    ): TenantRole {
        $existing = $this->entityManager
            ->getRepository(TenantRole::class)
            ->findOneBy(['tenant' => $tenant, 'name' => 'Advogado']);

        if ($existing !== null) {
            $io->text('  Perfil "Advogado" já existe.');
            return $existing;
        }

        $io->text('  Criando perfil "Advogado"...');

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Advogado');
        $role->setDescription('Acesso operacional padrão: módulos e recursos de Pastas, Clientes e Processos.');
        $role->setIsSystem(false);

        if (!$dryRun) {
            $this->entityManager->persist($role);
            foreach (self::ADVOGADO_PERMISSIONS as $code) {
                if (!isset($permissionMap[$code])) {
                    $io->warning(sprintf('Permissão "%s" não encontrada no catálogo — ignorada.', $code));
                    continue;
                }
                $trp = new TenantRolePermission();
                $trp->setTenantRole($role);
                $trp->setPermission($permissionMap[$code]);
                $this->entityManager->persist($trp);
            }
        }

        return $role;
    }

    /**
     * Carrega todas as permissões indexadas por código.
     *
     * @return array<string, Permission>
     */
    private function loadPermissionMap(): array
    {
        $permissions = $this->entityManager->getRepository(Permission::class)->findAll();

        $map = [];
        foreach ($permissions as $permission) {
            $map[$permission->getCode()] = $permission;
        }

        return $map;
    }
}
