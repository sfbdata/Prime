<?php

namespace App\Service;

use App\Entity\Auth\User;
use App\Entity\Permission\Permission;
use App\Entity\Ponto\Feriado;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Responsável pelo bootstrap de um novo tenant:
 *  1. Cria o perfil "Administrador do Escritório" (isSystem=true) com todas as permissões do catálogo.
 *  2. Vincula o usuário criador a esse perfil.
 *
 * Usado pelo TenantController na criação e pelas fixtures de desenvolvimento.
 * Idempotente: se o perfil admin já existir no tenant, não recria.
 */
class TenantBootstrapService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Cria o perfil admin do tenant e (opcionalmente) vincula o criador.
     *
     * @param Tenant    $tenant  Tenant recém-criado (deve estar persistido).
     * @param User|null $creator Usuário que será vinculado ao perfil admin.
     *
     * @return TenantRole O perfil "Administrador do Escritório" criado (ou já existente).
     */
    private const FERIADOS_NACIONAIS = [
        ['nome' => 'Confraternização Universal (Ano Novo)', 'mes' => 1,  'dia' => 1],
        ['nome' => 'Tiradentes',                            'mes' => 4,  'dia' => 21],
        ['nome' => 'Dia Mundial do Trabalho',               'mes' => 5,  'dia' => 1],
        ['nome' => 'Independência do Brasil',               'mes' => 9,  'dia' => 7],
        ['nome' => 'Nossa Senhora Aparecida',               'mes' => 10, 'dia' => 12],
        ['nome' => 'Finados',                               'mes' => 11, 'dia' => 2],
        ['nome' => 'Proclamação da República',              'mes' => 11, 'dia' => 15],
        ['nome' => 'Consciência Negra',                     'mes' => 11, 'dia' => 20],
        ['nome' => 'Natal',                                 'mes' => 12, 'dia' => 25],
    ];

    public function bootstrap(Tenant $tenant, ?User $creator = null): TenantRole
    {
        $adminRole = $this->findOrCreateAdminRole($tenant);

        if ($creator !== null) {
            $creator->setTenantRole($adminRole);
            $this->entityManager->persist($creator);
        }

        $this->seedFeriadosNacionais($tenant);

        $this->entityManager->flush();

        return $adminRole;
    }

    private function seedFeriadosNacionais(Tenant $tenant): void
    {
        foreach (self::FERIADOS_NACIONAIS as $dado) {
            $feriado = new Feriado();
            $feriado->setNome($dado['nome']);
            $feriado->setData(new \DateTime(sprintf('2000-%02d-%02d', $dado['mes'], $dado['dia'])));
            $feriado->setRecorrente(true);
            $feriado->setTenant($tenant);

            $this->entityManager->persist($feriado);
        }
    }

    private function findOrCreateAdminRole(Tenant $tenant): TenantRole
    {
        // Idempotente: não duplica se já existir
        $existing = $this->entityManager
            ->getRepository(TenantRole::class)
            ->findOneBy(['tenant' => $tenant, 'name' => 'Administrador do Escritório']);

        if ($existing !== null) {
            return $existing;
        }

        $adminRole = new TenantRole();
        $adminRole->setTenant($tenant);
        $adminRole->setName('Administrador do Escritório');
        $adminRole->setDescription('Perfil padrão com acesso total ao escritório. Não pode ser excluído.');
        $adminRole->setIsSystem(true);

        $this->entityManager->persist($adminRole);

        $this->attachAllPermissions($adminRole);

        return $adminRole;
    }

    private function attachAllPermissions(TenantRole $adminRole): void
    {
        /** @var Permission[] $permissions */
        $permissions = $this->entityManager
            ->getRepository(Permission::class)
            ->findAll();

        foreach ($permissions as $permission) {
            $trp = new TenantRolePermission();
            $trp->setTenantRole($adminRole);
            $trp->setPermission($permission);
            $this->entityManager->persist($trp);
        }
    }
}
