<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Profile\Entity\UserProfile;
use App\Tenant\Controller\TenantUserProfileController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Isolamento de tenant das rotas administrativas de perfil. `UserProfile` NÃO é TenantAware
 * (é 1:1 com User, entidade estrutural compartilhada) — o isolamento é TRANSITIVO via guards:
 *   (1) o User-alvo precisa ter vínculo ativo com o tenant da URL;
 *   (2) o admin precisa ser super-admin OU controlar o próprio tenant (com admin.users.manage).
 * Esses guards rodam ANTES de tocar/criar o perfil. O admin do teste tem TenantRole isSystem
 * (canAdminister=true no SEU tenant), provando que é o escopo de tenant — não a falta de
 * permissão — que barra o acesso cruzado.
 */
#[CoversClass(TenantUserProfileController::class)]
#[CoversClass(TenantController::class)]
final class PerfilAdminIsolamentoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Admin do tenant A não edita dados pessoais de user do tenant B (URL aponta p/ B) → 403')]
    public function testDadosPessoaisAdminDeOutroTenantRecebe403(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarAdminEscopo($tenantA);
        $userB   = $this->criarUsuario($tenantB);

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('POST', "/tenant/{$tenantB->getId()}/user/{$userB->getId()}/dados-pessoais", [
            'dados_pessoais' => ['nomeCompleto' => 'Sequestro'],
        ]);

        self::assertResponseStatusCodeSame(403, 'admin de outro tenant não pode editar perfil');
        $this->assertSemPerfil($userB);
    }

    #[TestDox('Admin não edita dados de user fora do tenant da URL (URL=A, user de B) → 404')]
    public function testDadosPessoaisUserForaDoTenantDaUrlRecebe404(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarAdminEscopo($tenantA);
        $userB   = $this->criarUsuario($tenantB);

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('POST', "/tenant/{$tenantA->getId()}/user/{$userB->getId()}/dados-pessoais", [
            'dados_pessoais' => ['nomeCompleto' => 'Sequestro'],
        ]);

        self::assertResponseStatusCodeSame(404, 'user de outro tenant não existe no escopo da URL');
        $this->assertSemPerfil($userB);
    }

    #[TestDox('Admin edita dados de user do próprio tenant: passa pelos guards (redireciona, não 403/404)')]
    public function testDadosPessoaisProprioTenantPassaPelosGuards(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $adminA  = $this->criarAdminEscopo($tenantA);
        $userA   = $this->criarUsuario($tenantA);

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('POST', "/tenant/{$tenantA->getId()}/user/{$userA->getId()}/dados-pessoais", [
            'dados_pessoais' => ['nomeCompleto' => 'Fulano de Tal'],
        ]);

        // Guards passaram → o controller redireciona (302), nunca 403/404
        self::assertResponseRedirects();
    }

    #[TestDox('Admin do tenant A não abre edit-role de user do tenant B → 403')]
    public function testEditRoleAdminDeOutroTenantRecebe403(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarAdminEscopo($tenantA);
        $userB   = $this->criarUsuario($tenantB);

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('GET', "/tenant/{$tenantB->getId()}/user/{$userB->getId()}/edit-role");

        self::assertResponseStatusCodeSame(403, 'admin de outro tenant não pode abrir o perfil/cargo de user alheio');
    }

    #[TestDox('edit-role de user fora do tenant da URL (URL=A, user de B) → 404 (Guard 1)')]
    public function testEditRoleUserForaDoTenantDaUrlRecebe404(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarAdminEscopo($tenantA);
        $userB   = $this->criarUsuario($tenantB);

        $this->logarComTenant($client, $adminA, $tenantA);
        // No editUserRole o Guard 2 (admin) vem antes do Guard 1 (vínculo); aqui o admin controla A,
        // então cai no Guard 1: o user-alvo não tem vínculo no tenant A → 404 (ramo distinto do 403).
        $client->request('GET', "/tenant/{$tenantA->getId()}/user/{$userB->getId()}/edit-role");

        self::assertResponseStatusCodeSame(404, 'user de outro tenant não existe no escopo da URL');
    }

    #[TestDox('Acesso bloqueado NÃO muta um perfil já existente do user-alvo')]
    public function testBloqueioNaoMutaPerfilExistente(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarAdminEscopo($tenantA);
        $userB   = $this->criarUsuario($tenantB);
        $this->criarPerfil($userB, 'Original Intacto');

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('POST', "/tenant/{$tenantB->getId()}/user/{$userB->getId()}/dados-pessoais", [
            'dados_pessoais' => ['nomeCompleto' => 'Sequestro'],
        ]);

        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $perfil = $em->getRepository(UserProfile::class)->findOneBy(['user' => $userB]);
        self::assertNotNull($perfil);
        self::assertSame('Original Intacto', $perfil->getNomeCompleto(), 'o perfil do user-alvo não pode ser mutado pelo acesso bloqueado');
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant PERFIL ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * Admin com TenantRole isSystem no tenant (canAdminister=true no SEU tenant), mas NÃO
     * super-admin global — para provar que o bloqueio cruzado é por escopo de tenant.
     */
    private function criarAdminEscopo(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('admin_' . uniqid() . '@test.com');
        $user->setFullName('Admin Escopo');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('user_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Alvo');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarPerfil(User $user, string $nomeCompleto): UserProfile
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $perfil = new UserProfile($user);
        $perfil->setNomeCompleto($nomeCompleto);
        $em->persist($perfil);
        $em->flush();

        return $perfil;
    }

    private function assertSemPerfil(User $user): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $perfil = $em->getRepository(UserProfile::class)->findOneBy(['user' => $user]);
        self::assertNull($perfil, 'o perfil do user-alvo não pode ser criado/tocado pelo acesso bloqueado');
    }
}
