<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Base dos testes de TELA do Dashboard: cria o escritório e um gestor com
 * `modules.bi.view` (o módulo `bi` é o que abre /dashboard) e já o loga.
 *
 * Os mesmos helpers vivem, privados, em DashboardControllerTest — foram
 * replicados aqui, e não movidos, para o teste histórico não mudar de forma
 * nesta rodada (ele só muda onde o desenho manda mudar rótulo).
 */
abstract class DashboardWebTestCase extends JusPrimeWebTestCase
{
    protected function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Dashboard ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    protected function criarUsuarioComPermissaoBi(Tenant $tenant): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $perm = $em->getRepository(Permission::class)->findOneBy(['code' => 'modules.bi.view']);

        if ($perm === null) {
            $perm = new Permission();
            $perm->setCode('modules.bi.view');
            $perm->setDescription('Acesso ao módulo BI (futuro)');
            $perm->setGroup('modules');
            $em->persist($perm);
            $em->flush();
        }

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor Dashboard ' . uniqid());
        $em->persist($role);

        $trp = new TenantRolePermission();
        $trp->setTenantRole($role);
        $trp->setPermission($perm);
        $em->persist($trp);
        $role->getTenantRolePermissions()->add($trp);

        $user = new User();
        $user->setEmail('dashboard_tela_' . uniqid() . '@test.com');
        $user->setFullName('Gestora da Tela');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);

        $em->flush();

        return $user;
    }

    /** Colaborador comum do mesmo escritório (aparece na tabela, não abre o painel). */
    protected function criarColaborador(Tenant $tenant, string $nome): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('dashboard_colab_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    /** @return array{0: User, 1: Tenant} gestor logado e o escritório dele */
    protected function criarGestorLogado(KernelBrowser $client): array
    {
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComPermissaoBi($tenant);
        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }
}
