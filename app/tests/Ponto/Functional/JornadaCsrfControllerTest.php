<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\JornadaColaboradorController;
use App\Ponto\Controller\JornadaTenantController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * C4a — endpoints de jornada (ponto eletrônico, ALTO) passam a exigir token CSRF no header
 * X-CSRF-Token (token 'ajax'). Sem token → 403; com token válido → sucesso. Prova a validação
 * do BACKEND (o interceptador JS que injeta o header é frontend → smoke/E2E).
 */
#[CoversClass(JornadaColaboradorController::class)]
#[CoversClass(JornadaTenantController::class)]
final class JornadaCsrfControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('jornada-colaborador SAVE sem token CSRF retorna 403')]
    public function testColaboradorSaveSemTokenRetorna403(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $admin   = $this->criarSuperAdmin();
        $alvo    = $this->criarUsuario([$tenant]);

        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $this->postJson($client, "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/jornada-colaborador", $this->payloadColaborador());

        self::assertResponseStatusCodeSame(403, 'save de jornada sem CSRF deveria ser barrado');
    }

    #[TestDox('jornada-colaborador SAVE com token CSRF válido tem sucesso')]
    public function testColaboradorSaveComTokenSucesso(): void
    {
        $client  = static::createClient();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $admin   = $this->criarSuperAdmin();
        $alvo    = $this->criarUsuario([$tenant]);

        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $this->postJson($client, "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/jornada-colaborador", $this->payloadColaborador(), 'TOKEN_ajax');

        self::assertResponseIsSuccessful('save de jornada com CSRF válido deveria suceder');
    }

    #[TestDox('jornada-colaborador DELETE sem token CSRF retorna 403')]
    public function testColaboradorDeleteSemTokenRetorna403(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $admin   = $this->criarSuperAdmin();
        $alvo    = $this->criarUsuario([$tenant]);

        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $client->request('DELETE', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/jornada-colaborador");

        self::assertResponseStatusCodeSame(403, 'delete de jornada sem CSRF deveria ser barrado');
    }

    #[TestDox('jornada-colaborador DELETE com token CSRF válido tem sucesso')]
    public function testColaboradorDeleteComTokenSucesso(): void
    {
        $client  = static::createClient();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $admin   = $this->criarSuperAdmin();
        $alvo    = $this->criarUsuario([$tenant]);

        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $client->request('DELETE', "/tenant/{$tenant->getId()}/user/{$alvo->getId()}/jornada-colaborador", [], [], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);

        self::assertResponseIsSuccessful('delete de jornada com CSRF válido deveria suceder');
    }

    #[TestDox('jornada-tenant SAVE sem token CSRF retorna 403')]
    public function testTenantSaveSemTokenRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarSuperAdmin();

        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $this->postJson($client, "/tenant/{$tenant->getId()}/jornada", $this->payloadTenant());

        self::assertResponseStatusCodeSame(403, 'save de jornada do tenant sem CSRF deveria ser barrado');
    }

    #[TestDox('jornada-tenant SAVE com token CSRF válido tem sucesso')]
    public function testTenantSaveComTokenSucesso(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $admin  = $this->criarSuperAdmin();

        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);

        $this->postJson($client, "/tenant/{$tenant->getId()}/jornada", $this->payloadTenant(), 'TOKEN_ajax');

        self::assertResponseIsSuccessful('save de jornada do tenant com CSRF válido deveria suceder');
    }

    // ----------------------------------------------------------------- helpers

    /** @param array<string,mixed> $payload */
    private function postJson(KernelBrowser $client, string $url, array $payload, ?string $csrf = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($csrf !== null) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }

        $client->request('POST', $url, [], [], $server, (string) json_encode($payload));
    }

    /** @return array<string,mixed> */
    private function payloadColaborador(): array
    {
        return ['cargaHorariaSemanal' => 2400, 'alertaHabilitado' => true, 'blocos' => []];
    }

    /** @return array<string,mixed> */
    private function payloadTenant(): array
    {
        return [
            'cargaHorariaSemanal'             => 2400,
            'alertaHabilitado'                => true,
            'blocos'                          => [],
            'validacaoRepousoHabilitada'      => true,
            'minimoMinutosRepouso'            => 60,
            'validacaoInterjornadaHabilitada' => true,
            'minimoMinutosInterjornada'       => 660,
        ];
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

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant CSRF JORNADA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** @param Tenant[] $tenants */
    private function criarUsuario(array $tenants): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('alvo_' . uniqid() . '@test.com');
        $user->setFullName('Alvo Jornada CSRF');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);
        foreach ($tenants as $tenant) {
            $em->persist(new UserTenant($user, $tenant));
        }
        $em->flush();

        return $user;
    }

    private function criarSuperAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('superadmin_' . uniqid() . '@test.com');
        $user->setFullName('Super Admin CSRF');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
