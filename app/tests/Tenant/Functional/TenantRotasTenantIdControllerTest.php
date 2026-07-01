<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * B5 frente 2 — as 5 rotas de escritório legadas (app_tenant_show/edit/delete/users/sedes) passam a
 * usar {tenantId} como parâmetro do tenant, alinhadas às outras 18 rotas do TenantController e
 * habilitando a trava automática (TenantUrlScopeListener) sem marcador. Rede de regressão da
 * RENOMEAÇÃO (comportamento preservado — a URL gerada não muda):
 *   (1) o parâmetro de rota se chama tenantId → generate(['tenantId' => id]) resolve a URL;
 *   (2) as páginas ainda renderizam (sidebar + templates) — pega qualquer path() com a chave antiga,
 *       que estoura MissingMandatoryParametersException (a única quebra possível do rename);
 *   (3) o #[MapEntity(id: 'tenantId')] resolve o Tenant pela URL — tenant inexistente → 404.
 */
#[CoversClass(TenantController::class)]
final class TenantRotasTenantIdControllerTest extends JusPrimeWebTestCase
{
    /** @return iterable<string, array{0: string, 1: string}> nome da rota → sufixo após /tenant/{tenantId} */
    public static function rotasComTenantId(): iterable
    {
        yield 'show'   => ['app_tenant_show', ''];
        yield 'edit'   => ['app_tenant_edit', '/edit'];
        yield 'delete' => ['app_tenant_delete', ''];
        yield 'users'  => ['app_tenant_users', '/users'];
        yield 'sedes'  => ['app_tenant_sedes', '/sedes'];
    }

    #[DataProvider('rotasComTenantId')]
    #[TestDox('A rota usa o parâmetro {tenantId} (generate com tenantId resolve a URL)')]
    public function testRotaExpoeParametroTenantId(string $rota, string $sufixo): void
    {
        static::createClient();
        $gerador = static::getContainer()->get('router');

        self::assertSame('/tenant/42' . $sufixo, $gerador->generate($rota, ['tenantId' => 42]));
    }

    #[TestDox('As páginas das rotas GET renderizam (sidebar + templates com a chave nova) e tenant inexistente dá 404')]
    public function testPaginasRenderizamETenantInexistenteDa404(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $this->logarComTenant($client, $admin, $tenant);

        $id = $tenant->getId();
        foreach (["/tenant/{$id}", "/tenant/{$id}/edit", "/tenant/{$id}/users", "/tenant/{$id}/sedes", '/tenant'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful("a página {$url} deveria renderizar (path() com a chave 'id' antiga estoura aqui)");
        }

        $client->request('GET', '/tenant/99999999/users');
        self::assertResponseStatusCodeSame(404, 'tenant inexistente deve dar 404 — prova do MapEntity(id: tenantId)');
    }

    #[TestDox('app_tenant_delete (POST) resolve pelo {tenantId} e faz SOFT delete do tenant (super-admin + CSRF) → 303')]
    public function testDeletePostResolvePeloTenantId(): void
    {
        $client     = static::createClient();
        $this->instalarCsrfStorage();
        $tenant     = $this->criarTenant();
        $superAdmin = $this->criarSuperAdmin();
        $id         = (int) $tenant->getId();

        $client->loginUser($superAdmin);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/tenant/{$id}", ['_token' => 'TOKEN_delete' . $id]);
        self::assertResponseRedirects('/tenant', 303, 'delete deveria resolver pelo {tenantId} e redirecionar ao índice');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $t = $em->find(Tenant::class, $id);
        // RS08: exclusão virou soft delete — o tenant permanece no banco, apenas desativado.
        self::assertNotNull($t, 'soft delete não remove o tenant do banco');
        self::assertFalse($t->isActive(), 'o tenant deveria ter sido desativado (soft delete)');
        self::assertNotNull($t->getExcluidoEm(), 'a quarentena (excluidoEm) deveria estar marcada');
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant ROTA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** Admin do escritório: vínculo ativo + TenantRole isSystem (canAdminister=true em qualquer módulo). */
    private function criarAdmin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('admin_rota_' . uniqid() . '@test.com');
        $user->setFullName('Admin Rota');
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

    private function criarSuperAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('superadmin_rota_' . uniqid() . '@test.com');
        $user->setFullName('Super Admin Rota');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
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
}
