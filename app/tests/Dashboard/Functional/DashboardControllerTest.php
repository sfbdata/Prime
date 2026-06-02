<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(DashboardController::class)]
final class DashboardControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Dashboard ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuarioSemPermissao(Tenant $tenant): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail('dashboard_sem_perm_' . uniqid() . '@test.com');
        $user->setFullName('Sem Permissão');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $em->persist($ut);

        $em->flush();

        return $user;
    }

    private function criarUsuarioComPermissaoBi(Tenant $tenant): User
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
        $user->setEmail('dashboard_com_perm_' . uniqid() . '@test.com');
        $user->setFullName('Com Permissão BI');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);

        $em->flush();

        return $user;
    }

    #[TestDox('GET /dashboard sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /dashboard autenticado sem modules.bi.view retorna 403')]
    public function testSemPermissaoRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioSemPermissao($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/dashboard');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('GET /dashboard autenticado com modules.bi.view retorna 200')]
    public function testComPermissaoRetorna200(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComPermissaoBi($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('GET /dashboard renderiza cards zerados para tenant sem dados')]
    public function testRenderizaCardsZeradosParaTenantVazio(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComPermissaoBi($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.db-stat-card');
        self::assertSelectorTextContains('body', 'Nenhum advogado com dados no período.');
    }

    #[TestDox('GET /dashboard renderiza nome do advogado e linha na tabela com dados reais')]
    public function testRenderizaDadosReaisNaTabela(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioComPermissaoBi($tenant);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $tarefa = TarefaFactory::createOne([
            'pasta'  => $pasta,
            'status' => Tarefa::STATUS_PENDENTE,
        ])->_real();
        $tarefa->addResponsavel($user);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table tbody', $user->getFullName());
        self::assertSelectorExists('table tbody tr');
    }
}
