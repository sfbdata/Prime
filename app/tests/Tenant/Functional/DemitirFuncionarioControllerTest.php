<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use App\Tests\Functional\JusPrimeWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(TenantController::class)]
final class DemitirFuncionarioControllerTest extends JusPrimeWebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Demissao ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarAdmin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('admin_demissao_' . uniqid() . '@test.com');
        $user->setFullName('Admin Demissão');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarFuncionario(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('func_' . uniqid() . '@test.com');
        $user->setFullName('Funcionário Teste');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
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

    #[TestDox('POST demitir sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/tenant/1/user/1/demitir');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST demitir com CSRF inválido retorna 403')]
    public function testTokenCsrfInvalidoRetorna403(): void
    {
        $client     = static::createClient();
        $tenant     = $this->criarTenant();
        $admin      = $this->criarAdmin($tenant);
        $funcionario = $this->criarFuncionario($tenant);

        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$funcionario->getId()}/demitir", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST demitir sem substituto redireciona com flash de sucesso')]
    public function testDemitirSemSubstitutoRetorna302(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $funcionario = $this->criarFuncionario($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$funcionario->getId()}/demitir", [
            '_token' => $this->gerarCsrf('demitir_' . $funcionario->getId()),
        ]);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/users");

        $em              = static::getContainer()->get(EntityManagerInterface::class);
        $funcionarioAtualizado = $em->find(User::class, $funcionario->getId());
        self::assertNotNull($funcionarioAtualizado);
        self::assertTrue($funcionarioAtualizado->isActive());

        $vinculo = $em->getRepository(UserTenant::class)->findOneBy([
            'user'   => $funcionario,
            'tenant' => $tenant,
        ]);
        self::assertNotNull($vinculo);
        self::assertFalse($vinculo->isActive());
        self::assertNotNull($vinculo->getDemitidoEm());
    }

    #[TestDox('POST demitir com substituto válido redireciona com flash de sucesso')]
    public function testDemitirComSubstitutoRetorna302(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $funcionario = $this->criarFuncionario($tenant);
        $substituto  = $this->criarFuncionario($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$funcionario->getId()}/demitir", [
            '_token'       => $this->gerarCsrf('demitir_' . $funcionario->getId()),
            'substituto_id' => $substituto->getId(),
        ]);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/users");

        $em              = static::getContainer()->get(EntityManagerInterface::class);
        $funcionarioAtualizado = $em->find(User::class, $funcionario->getId());
        self::assertNotNull($funcionarioAtualizado);
        self::assertTrue($funcionarioAtualizado->isActive());
    }

    #[TestDox('POST demitir a si mesmo redireciona com flash de erro')]
    public function testNaoPermiteDemitirAsiMesmo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$admin->getId()}/demitir", [
            '_token' => $this->gerarCsrf('demitir_' . $admin->getId()),
        ]);

        self::assertResponseRedirects();

        $em           = static::getContainer()->get(EntityManagerInterface::class);
        $adminAtualizado = $em->find(User::class, $admin->getId());
        self::assertNotNull($adminAtualizado);
        self::assertTrue($adminAtualizado->isActive());
    }

    #[TestDox('POST demitir por usuário sem permissão retorna 403')]
    public function testNaoPermiteAcessoSemPermissao(): void
    {
        $client      = static::createClient();
        $em          = static::getContainer()->get(EntityManagerInterface::class);
        $tenant      = $this->criarTenant();
        $semPermissao = $this->criarFuncionario($tenant);
        $alvo        = $this->criarFuncionario($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $semPermissao, $tenant);

        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/demitir", [
            '_token' => $this->gerarCsrf('demitir_' . $alvo->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
