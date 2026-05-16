<?php

namespace App\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Ponto\Feriado;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Expediente\Entity\Marcador;
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
    private const MARCADORES_PADRAO = [
        ['nome' => 'Nova Pasta',   'ordem' => 1, 'filhos' => []],
        ['nome' => 'Distribuídas', 'ordem' => 2, 'filhos' => [
            ['nome' => 'Advogado 1',           'ordem' => 1],
            ['nome' => 'Advogado 2',           'ordem' => 2],
            ['nome' => 'Advogado 3',           'ordem' => 3],
            ['nome' => 'Advogado 4',           'ordem' => 4],
            ['nome' => 'Setor Administrativo', 'ordem' => 5],
        ]],
        ['nome' => 'Realizadas',   'ordem' => 3, 'filhos' => []],
        ['nome' => 'Protocolar',   'ordem' => 4, 'filhos' => []],
        ['nome' => 'Sem prazo',    'ordem' => 5, 'filhos' => []],
        ['nome' => 'Acervo Geral', 'ordem' => 6, 'filhos' => [
            ['nome' => 'Arquivadas', 'ordem' => 1],
            ['nome' => 'Ativas',     'ordem' => 2],
        ]],
    ];

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
            $this->entityManager->persist($creator);

            $userTenant = $this->entityManager
                ->getRepository(UserTenant::class)
                ->findOneBy(['user' => $creator, 'tenant' => $tenant]);
            if ($userTenant === null) {
                $userTenant = new UserTenant($creator, $tenant);
                $this->entityManager->persist($userTenant);
            }
            $userTenant->setTenantRole($adminRole);
            $tenant->setCriadoPor($creator);
            $this->entityManager->persist($tenant);
        }

        $this->seedFeriadosNacionais($tenant);

        if ($creator !== null) {
            $this->seedMarcadoresPadrao($tenant, $creator);
        }

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

    private function seedMarcadoresPadrao(Tenant $tenant, User $criador): void
    {
        foreach (self::MARCADORES_PADRAO as $dado) {
            $marcador = new Marcador($dado['nome'], $tenant, $criador);
            $marcador->setOrdem($dado['ordem']);
            $this->entityManager->persist($marcador);

            foreach ($dado['filhos'] as $filhoDado) {
                $filho = new Marcador($filhoDado['nome'], $tenant, $criador, $marcador);
                $filho->setOrdem($filhoDado['ordem']);
                $this->entityManager->persist($filho);
            }
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
