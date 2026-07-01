<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\HomeOfficeConfigController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Admin da config de home office: salvar/ler/apagar, validações de payload, permissão e IDOR
 * (usuário-alvo tem que pertencer ao tenant da URL). Guards espelham JornadaColaboradorController.
 */
#[CoversClass(HomeOfficeConfigController::class)]
final class HomeOfficeConfigControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Admin salva a config e a leitura devolve os mesmos valores')]
    public function testSalvarELerConfig(): void
    {
        $client = $this->criarClientePreparado();
        $tenant = $this->criarTenant();
        $admin  = $this->criarSuperAdmin($tenant);
        $alvo   = $this->criarColaborador($tenant);

        $this->logarComTenant($client, $admin, $tenant);

        $this->post($client, $tenant, $alvo, [
            'diasSemana'     => [3, 1],
            'datasAvulsas'   => ['2026-07-20'],
            'vigenciaInicio' => '2026-07-01',
            'vigenciaFim'    => '2026-07-31',
            'ativo'          => true,
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', $this->url($tenant, $alvo));
        self::assertResponseIsSuccessful();

        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame([1, 3], $json['diasSemana']); // normalizado/ordenado
        self::assertSame(['2026-07-20'], $json['datasAvulsas']);
        self::assertSame('2026-07-01', $json['vigenciaInicio']);
        self::assertSame('2026-07-31', $json['vigenciaFim']);
        self::assertTrue($json['ativo']);
    }

    #[TestDox('DELETE remove a config e a leitura volta ao padrão vazio')]
    public function testDeleteRemoveConfig(): void
    {
        $client = $this->criarClientePreparado();
        $tenant = $this->criarTenant();
        $admin  = $this->criarSuperAdmin($tenant);
        $alvo   = $this->criarColaborador($tenant);

        $this->logarComTenant($client, $admin, $tenant);

        $this->post($client, $tenant, $alvo, ['diasSemana' => [1], 'ativo' => true]);
        self::assertResponseIsSuccessful();

        $client->request('DELETE', $this->url($tenant, $alvo), [], [], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);
        self::assertResponseIsSuccessful();

        $client->request('GET', $this->url($tenant, $alvo));
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame([], $json['diasSemana']);
        self::assertNull($json['vigenciaInicio']);
    }

    #[TestDox('Vigência invertida (início > fim) retorna 422')]
    public function testVigenciaInvertidaRetorna422(): void
    {
        $client = $this->criarClientePreparado();
        $tenant = $this->criarTenant();
        $admin  = $this->criarSuperAdmin($tenant);
        $alvo   = $this->criarColaborador($tenant);

        $this->logarComTenant($client, $admin, $tenant);

        $this->post($client, $tenant, $alvo, [
            'diasSemana'     => [1],
            'vigenciaInicio' => '2026-07-31',
            'vigenciaFim'    => '2026-07-01',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Dia da semana inválido (fora de 1-7) retorna 422')]
    public function testDiaInvalidoRetorna422(): void
    {
        $client = $this->criarClientePreparado();
        $tenant = $this->criarTenant();
        $admin  = $this->criarSuperAdmin($tenant);
        $alvo   = $this->criarColaborador($tenant);

        $this->logarComTenant($client, $admin, $tenant);

        $this->post($client, $tenant, $alvo, ['diasSemana' => [8]]);

        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Usuário sem permissão de admin não pode gerenciar (403)')]
    public function testSemPermissaoNega(): void
    {
        $client = $this->criarClientePreparado();
        $tenant   = $this->criarTenant();
        $semPoder = $this->criarColaborador($tenant); // sem admin.users.manage nem super-admin
        $alvo     = $this->criarColaborador($tenant);

        $this->logarComTenant($client, $semPoder, $tenant);

        $this->post($client, $tenant, $alvo, ['diasSemana' => [1]]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('IDOR: usuário-alvo de outro tenant retorna 404 mesmo para super-admin')]
    public function testIdorUsuarioDeOutroTenant(): void
    {
        $client  = $this->criarClientePreparado();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $admin   = $this->criarSuperAdmin($tenantA);
        $alvoB   = $this->criarColaborador($tenantB); // pertence só ao tenant B

        $this->logarComTenant($client, $admin, $tenantA);

        // URL usa o tenant A, mas o alvo é do tenant B → sem vínculo → 404.
        $this->post($client, $tenantA, $alvoB, ['diasSemana' => [1]]);

        self::assertResponseStatusCodeSame(404);
    }

    // ----------------------------------------------------------------- helpers

    private function url(Tenant $tenant, User $user): string
    {
        return sprintf('/tenant/%d/user/%d/home-office', $tenant->getId(), $user->getId());
    }

    /** @param array<string, mixed> $payload */
    private function post(KernelBrowser $client, Tenant $tenant, User $user, array $payload): void
    {
        $client->request(
            'POST',
            $this->url($tenant, $user),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            (string) json_encode($payload),
        );
    }

    private function criarClientePreparado(): KernelBrowser
    {
        $client = static::createClient();
        // Sem reboot entre requisições: preserva o storage de CSRF customizado em testes com 2+ requests.
        $client->disableReboot();

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);

        return $client;
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant HO ADMIN ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarSuperAdmin(Tenant $tenant): User
    {
        return $this->criarUsuario($tenant, ['ROLE_SUPER_ADMIN'], 'ho_admin_');
    }

    private function criarColaborador(Tenant $tenant): User
    {
        return $this->criarUsuario($tenant, ['ROLE_USER'], 'ho_colab_');
    }

    /** @param string[] $roles */
    private function criarUsuario(Tenant $tenant, array $roles, string $prefixo): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($prefixo . uniqid() . '@test.com');
        $user->setFullName('User HO');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }
}
