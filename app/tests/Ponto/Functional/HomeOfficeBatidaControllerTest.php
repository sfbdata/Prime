<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Home office: a batida do colaborador liberado passa SEM GPS e grava o registro como remoto
 * (homeOffice=true, sede/lat/lng nulos). Sem liberação, o geofencing segue valendo (422 sem GPS,
 * 403 fora do raio) — regressão preservada.
 */
#[CoversClass(PontoController::class)]
final class HomeOfficeBatidaControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Home office: batida sem GPS é aceita e gravada como remota (sede/lat nulos)')]
    public function testHomeOfficeLiberaBatidaSemGps(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $user   = $this->criarSuperAdmin($tenant);
        $this->criarConfigTodosOsDias($user, $tenant);

        $this->logarComTenant($client, $user, $tenant);
        $this->postarBatida($client, ['tipo' => 'entrada']); // sem latitude/longitude

        self::assertResponseIsSuccessful('batida em home office sem GPS deveria ser aceita');

        $registros = $this->registrosDoUsuario($user);
        self::assertCount(1, $registros);
        self::assertTrue($registros[0]->isHomeOffice());
        self::assertNull($registros[0]->getSede());
        self::assertNull($registros[0]->getLatitude());
        self::assertNull($registros[0]->getLongitude());
    }

    #[TestDox('Home office libera mesmo sem nenhuma sede cadastrada no tenant')]
    public function testHomeOfficeLiberaSemSedeCadastrada(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant(); // nenhuma sede
        $user   = $this->criarSuperAdmin($tenant);
        $this->criarConfigTodosOsDias($user, $tenant);

        $this->logarComTenant($client, $user, $tenant);
        $this->postarBatida($client, ['tipo' => 'entrada']);

        self::assertResponseIsSuccessful('home office deveria pular a checagem de sede');
    }

    #[TestDox('Sem home office, batida sem GPS ainda é barrada (422) — regressão do geofencing')]
    public function testSemHomeOfficeExigeGps(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $user   = $this->criarSuperAdmin($tenant); // sem config de home office

        $this->logarComTenant($client, $user, $tenant);
        $this->postarBatida($client, ['tipo' => 'entrada']); // sem GPS

        self::assertResponseStatusCodeSame(422, 'sem home office, GPS continua obrigatório');
    }

    #[TestDox('Home office não pula a validação de repouso mínimo (retorno cedo demais → 422)')]
    public function testHomeOfficeMantemValidacaoDeRepouso(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $user   = $this->criarSuperAdmin($tenant);
        $this->criarConfigTodosOsDias($user, $tenant);
        // Repouso registrado agora → um RETORNO imediato viola o intervalo mínimo (default 60 min).
        $this->criarRegistro($user, $tenant, RegistroPonto::TIPO_REPOUSO);

        $this->logarComTenant($client, $user, $tenant);
        $this->postarBatida($client, ['tipo' => 'retorno']); // home office, sem GPS

        self::assertResponseStatusCodeSame(422, 'repouso/interjornada devem valer também em home office');
    }

    #[TestDox('Sem home office, batida fora do raio da sede ainda é bloqueada (403)')]
    public function testSemHomeOfficeForaDoRaioBloqueia(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $this->adicionarSede($tenant); // sede em São Paulo, raio 100m
        $user = $this->criarSuperAdmin($tenant);

        $this->logarComTenant($client, $user, $tenant);
        // Coordenadas muito distantes da sede → fora do raio.
        $this->postarBatida($client, ['tipo' => 'entrada', 'latitude' => 0.0, 'longitude' => 0.0, 'precisaoGps' => 10.0]);

        self::assertResponseStatusCodeSame(403, 'fora do raio, sem home office, deveria bloquear');
    }

    // ----------------------------------------------------------------- helpers

    /** @param array<string, mixed> $payload */
    private function postarBatida(object $client, array $payload): void
    {
        $client->request(
            'POST',
            '/ponto/batida',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'],
            (string) json_encode($payload),
        );
    }

    /** @return RegistroPonto[] */
    private function registrosDoUsuario(User $user): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em->getRepository(RegistroPonto::class)->findBy(['user' => $user]);
    }

    private function criarRegistro(User $user, Tenant $tenant, string $tipo): RegistroPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTime());
        $em->persist($registro);
        $em->flush();

        return $registro;
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
        $tenant->setName('Tenant HO BATIDA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function adicionarSede(Tenant $tenant): Sede
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sede = new Sede();
        $sede->setNome('Sede ' . uniqid());
        $sede->setLatitude('-23.55000000');
        $sede->setLongitude('-46.63000000');
        $sede->setRaioPermitido(100);
        $sede->setTimezone('America/Sao_Paulo');
        $sede->setTenant($tenant);
        $em->persist($sede);
        $em->flush();

        return $sede;
    }

    private function criarSuperAdmin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('ho_batida_' . uniqid() . '@test.com');
        $user->setFullName('User HO Batida');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    private function criarConfigTodosOsDias(User $user, Tenant $tenant): HomeOfficeConfig
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $config = new HomeOfficeConfig();
        $config->setUser($user);
        $config->setTenant($tenant);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]); // liberado todo dia → cobre "hoje"
        $config->setAtivo(true);
        $em->persist($config);
        $em->flush();

        return $config;
    }
}
