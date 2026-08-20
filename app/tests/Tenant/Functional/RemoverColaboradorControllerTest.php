<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use App\Tests\Functional\JusPrimeWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Substitui DemitirFuncionarioControllerTest — cobre a rota app_tenant_user_remover.
 * Os helpers criarTenant/criarAdmin/criarFuncionario são copiados de lá (padrão de CSRF
 * fake + login já validado). O caso do coração da decisão D7 (super-admin não passa por
 * cima da trava do último administrador) usa um helper próprio, criarAdminDoEscritorio,
 * porque criarAdmin() só marca ROLE_SUPER_ADMIN no User — não cria o vínculo com
 * TenantRole::isSystem=true que RemoverColaboradorDoEscritorioUseCase::ehUltimoAdmin()
 * exige para reconhecer alguém como administrador DO ESCRITÓRIO.
 */
#[CoversClass(TenantController::class)]
final class RemoverColaboradorControllerTest extends JusPrimeWebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Remover ' . uniqid());
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
        $user->setEmail('admin_remover_' . uniqid() . '@test.com');
        $user->setFullName('Admin Remover');
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
        $user->setEmail('func_remover_' . uniqid() . '@test.com');
        $user->setFullName('Funcionário Teste');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    /**
     * Administrador DO ESCRITÓRIO de verdade: vínculo com TenantRole::isSystem=true, o que
     * UserTenantRepository::contarAdminsAtivos() e ehUltimoAdmin() exigem. Só existe nesta
     * classe porque a trava do último admin (D7) é nova — DemitirFuncionarioUseCase não tinha.
     */
    private function criarAdminDoEscritorio(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador Master');
        $role->setIsSystem(true);
        $em->persist($role);

        $user = new User();
        $user->setEmail('admin_escritorio_' . uniqid() . '@test.com');
        $user->setFullName('Administrador do Escritório');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
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

    #[TestDox('POST remover sem substituto redireciona com 302 e apaga o vínculo')]
    public function testRemoverSemSubstitutoRetorna302EApagaOVinculo(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $funcionario = $this->criarFuncionario($tenant);

        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$funcionario->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $funcionario->getId()),
        ]);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/users");

        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $vinculo  = $em->getRepository(UserTenant::class)->findOneBy([
            'user'   => $funcionario,
            'tenant' => $tenant,
        ]);
        self::assertNull($vinculo, 'o vínculo deveria ter sido apagado (hard delete)');

        // A conta continua existindo — a remoção é do vínculo, não da pessoa.
        $funcionarioAtualizado = $em->find(User::class, $funcionario->getId());
        self::assertNotNull($funcionarioAtualizado);
    }

    #[TestDox('POST remover com CSRF inválido retorna 403')]
    public function testTokenCsrfInvalidoRetorna403(): void
    {
        $client      = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $funcionario = $this->criarFuncionario($tenant);

        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$funcionario->getId()}/remover", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST remover por usuário sem admin.users.manage retorna 403')]
    public function testNaoPermiteAcessoSemPermissaoRetorna403(): void
    {
        $client       = static::createClient();
        $tenant       = $this->criarTenant();
        $semPermissao = $this->criarFuncionario($tenant);
        $alvo         = $this->criarFuncionario($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $semPermissao, $tenant);

        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $alvo->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST remover alvo com vínculo em outro escritório retorna 404 (B-route)')]
    public function testAlvoDeOutroEscritorioRetorna404(): void
    {
        $client   = static::createClient();
        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $admin    = $this->criarAdmin($tenantA);
        $alvoDeB  = $this->criarFuncionario($tenantB);

        $this->instalarCsrfStorage();
        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/tenant/{$tenantA->getId()}/user/{$alvoDeB->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $alvoDeB->getId()),
        ]);

        self::assertResponseStatusCodeSame(404);

        // O vínculo de B não pode ter sido tocado.
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $vinculo = $em->getRepository(UserTenant::class)->findOneBy([
            'user'   => $alvoDeB,
            'tenant' => $tenantB,
        ]);
        self::assertNotNull($vinculo, 'o vínculo do outro escritório não deveria ter sido tocado');
    }

    #[TestDox('nem super-admin remove o ultimo administrador do escritorio (D7)')]
    public function testSuperAdminNaoRemoveOUltimoAdmin(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $this->criarTenant();

        // Único administrador do escritório — vínculo com TenantRole::isSystem=true.
        $unicoAdmin = $this->criarAdminDoEscritorio($tenant);

        // O executor é super-admin e NÃO tem vínculo com este escritório: o guard de
        // permissão do controller o deixa passar de qualquer forma (isSuperAdmin bypassa
        // canAdminister). A trava real precisa vir do UseCase, não do controller.
        $suporte = new User();
        $suporte->setEmail('suporte_' . uniqid() . '@test.com');
        $suporte->setFullName('Suporte');
        $suporte->setRoles(['ROLE_SUPER_ADMIN']);
        $suporte->setIsActive(true);
        $em->persist($suporte);
        $em->flush();

        $this->instalarCsrfStorage();
        $client->loginUser($suporte);
        $this->marcarTermosAceitos($client);

        $client->request('POST', "/tenant/{$tenant->getId()}/user/{$unicoAdmin->getId()}/remover", [
            '_token' => $this->gerarCsrf('remover_' . $unicoAdmin->getId()),
        ]);

        // O UseCase recusa (InvalidArgumentException) e o controller responde com flash de
        // erro + redirect — não é um 403/404, é a operação sendo negada e revertida.
        self::assertResponseRedirects("/tenant/{$tenant->getId()}/users");

        $em->clear();
        $vinculo = static::getContainer()->get(UserTenantRepository::class)
            ->findPorUserETenant(
                $em->find(User::class, $unicoAdmin->getId()),
                $em->find(Tenant::class, $tenant->getId())
            );

        self::assertNotNull($vinculo, 'o super-admin conseguiu remover o último administrador');
    }
}
