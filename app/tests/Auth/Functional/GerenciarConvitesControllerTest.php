<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\GerenciarConvitesController;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(GerenciarConvitesController::class)]
final class GerenciarConvitesControllerTest extends WebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Convites ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarPermissaoConvite(): Permission
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $perm = $em->getRepository(Permission::class)->findOneBy(['code' => 'admin.users.invite']);

        if ($perm !== null) {
            return $perm;
        }

        $perm = new Permission();
        $perm->setCode('admin.users.invite');
        $perm->setDescription('Convidar usuários para o tenant');
        $perm->setGroup('admin');
        $em->persist($perm);
        $em->flush();

        return $perm;
    }

    private function criarRoleComPermissaoConvite(Tenant $tenant): TenantRole
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $perm = $this->criarPermissaoConvite();

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor Convites ' . uniqid());
        $em->persist($role);

        $trp = new TenantRolePermission();
        $trp->setTenantRole($role);
        $trp->setPermission($perm);
        $em->persist($trp);
        $role->getTenantRolePermissions()->add($trp);

        $em->flush();

        return $role;
    }

    private function criarAdminComPermissao(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $role = $this->criarRoleComPermissaoConvite($tenant);

        $user = new User();
        $user->setEmail('admin_inv_' . uniqid() . '@test.com');
        $user->setFullName('Admin Convites');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);

        $em->flush();

        return $user;
    }

    private function criarUsuarioSemPermissao(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('sem_perm_' . uniqid() . '@test.com');
        $user->setFullName('Sem Permissão');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $em->persist($ut);

        $em->flush();

        return $user;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    private function logarComTenant($client, User $user, Tenant $tenant): void
    {
        $client->loginUser($user);
        $session = $client->getSession();
        $session->set('current_tenant_id', $tenant->getId());
        $session->save();
    }

    #[TestDox('GET /escritorio/convites sem autenticação redireciona para login')]
    public function testGetSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/escritorio/convites');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /escritorio/convites sem permissão admin.users.invite retorna 403')]
    public function testGetSemPermissaoRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarUsuarioSemPermissao($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/escritorio/convites');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('GET /escritorio/convites com permissão retorna 200')]
    public function testGetComPermissaoRetorna200(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/escritorio/convites');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('POST criar com email novo cria convite de escritório')]
    public function testPostCriarEmailNovoCriaConviteEscritorio(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $role   = $this->criarRoleComPermissaoConvite($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $roles  = $em->getRepository(TenantRole::class)->findBy(['tenant' => $tenant]);
        $roleId = $roles[0]->getId();

        $email = 'office_novo_' . uniqid() . '@example.com';
        $client->request('POST', '/escritorio/convites/novo', [
            'email'          => $email,
            'full_name'      => 'Novo Colaborador',
            'tenant_role_id' => $roleId,
            '_token'         => $this->gerarCsrf('convite_novo'),
        ]);

        self::assertResponseRedirects('/escritorio/convites');

        $em->clear();
        $invitation = $em->getRepository(Invitation::class)->findOneBy(['email' => $email]);
        self::assertNotNull($invitation);
        self::assertSame('office', $invitation->getType());
        self::assertSame('pending', $invitation->getStatus());
    }

    #[TestDox('POST criar com membro ativo redireciona com flash de erro')]
    public function testPostCriarMembroAtivoRedirecionaComErro(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $roles  = $em->getRepository(TenantRole::class)->findBy(['tenant' => $tenant]);
        $roleId = $roles[0]->getId();

        $client->request('POST', '/escritorio/convites/novo', [
            'email'          => $user->getEmail(),
            'tenant_role_id' => $roleId,
            '_token'         => $this->gerarCsrf('convite_novo'),
        ]);

        self::assertResponseRedirects('/escritorio/convites');

        $client->followRedirect();
        self::assertSelectorExists('.alert-erro');
    }

    #[TestDox('POST revogar convite pending do tenant altera status para revoked')]
    public function testPostRevogarConvitePending(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $invitation = new Invitation(
            email: 'revogar_office_' . uniqid() . '@example.com',
            token: bin2hex(random_bytes(16)),
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setTenant($tenant);
        $em->persist($invitation);
        $em->flush();

        $client->request('POST', '/escritorio/convites/' . $invitation->getToken() . '/revogar', [
            '_token' => $this->gerarCsrf('convite_revogar_' . $invitation->getToken()),
        ]);

        self::assertResponseRedirects('/escritorio/convites');

        $em->clear();
        $atualizado = $em->find(Invitation::class, $invitation->getId());
        self::assertSame('revoked', $atualizado->getStatus());
    }

    #[TestDox('POST reenviar com limite atingido redireciona com flash de erro')]
    public function testPostReenviarComLimiteAtingidoRedirecionaComErro(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $user   = $this->criarAdminComPermissao($tenant);
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $invitation = new Invitation(
            email: 'limite_' . uniqid() . '@example.com',
            token: bin2hex(random_bytes(16)),
            type: 'office',
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $invitation->setTenant($tenant);
        $invitation->incrementarReenvio();
        $invitation->incrementarReenvio();
        $invitation->incrementarReenvio();
        $em->persist($invitation);
        $em->flush();

        $client->request('POST', '/escritorio/convites/' . $invitation->getToken() . '/reenviar', [
            '_token' => $this->gerarCsrf('convite_reenviar_' . $invitation->getToken()),
        ]);

        self::assertResponseRedirects('/escritorio/convites');

        $client->followRedirect();
        self::assertSelectorExists('.alert-erro');
    }
}
