<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tenant\Controller\EscritorioController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(EscritorioController::class)]
final class EscritorioControllerTest extends JusPrimeWebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Sair ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** @return array{0: User, 1: UserTenant} */
    private function criarUsuarioComVinculo(Tenant $tenant, bool $admin = false): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('sair_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Sair');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        if ($admin) {
            $role = new TenantRole();
            $role->setTenant($tenant);
            $role->setName('Administrador do Escritório');
            $role->setIsSystem(true);
            $em->persist($role);
            $vinculo->setTenantRole($role);
        }
        $em->persist($vinculo);
        $em->flush();

        return [$user, $vinculo];
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

    #[TestDox('POST sair sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/escritorio/1/sair');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST sair com CSRF inválido retorna 403')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        [$user]   = $this->criarUsuarioComVinculo($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/sair", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST sair de colaborador inativa o vínculo e mantém demitidoEm null')]
    public function testSaiComSucesso(): void
    {
        $client           = static::createClient();
        $tenant           = $this->criarTenant();
        [$user, $vinculo] = $this->criarUsuarioComVinculo($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/sair", [
            '_token' => $this->gerarCsrf('sair_' . $tenant->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $atualizado = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNotNull($atualizado);
        self::assertFalse($atualizado->isActive());
        self::assertNull($atualizado->getDemitidoEm());
    }

    #[TestDox('POST sair sendo o único admin é bloqueado e mantém o vínculo ativo')]
    public function testUltimoAdminBloqueado(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        [$user]   = $this->criarUsuarioComVinculo($tenant, admin: true);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/sair", [
            '_token' => $this->gerarCsrf('sair_' . $tenant->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $atualizado = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNotNull($atualizado);
        self::assertTrue($atualizado->isActive());
    }

    #[TestDox('Sair do escritório ATIVO limpa o tenant da sessão (RS07)')]
    public function testSaiDoAtivoLimpaSessao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$user] = $this->criarUsuarioComVinculo($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/sair", [
            '_token' => $this->gerarCsrf('sair_' . $tenant->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertNull($client->getSession()->get('current_tenant_id'));
    }

    #[TestDox('Sair de escritório NÃO-ativo mantém o tenant ativo na sessão e inativa só o vínculo certo')]
    public function testSaiDeNaoAtivoMantemSessao(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        [$user]  = $this->criarUsuarioComVinculo($tenantA);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new UserTenant($user, $tenantB));
        $em->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenantA);
        $client->request('POST', "/escritorio/{$tenantB->getId()}/sair", [
            '_token' => $this->gerarCsrf('sair_' . $tenantB->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertSame($tenantA->getId(), $client->getSession()->get('current_tenant_id'));

        $em2      = static::getContainer()->get(EntityManagerInterface::class);
        $vinculoB = $em2->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenantB]);
        self::assertNotNull($vinculoB);
        self::assertFalse($vinculoB->isActive());
    }
}
