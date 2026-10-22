<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Form\SedeType;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A latitude, a longitude e o raio da sede são a cerca do ponto eletrônico. Valor vazio, fora da
 * faixa ou não numérico vira erro de validação no campo (tela renderizada de novo, nada gravado).
 * Antes, a longitude ou a latitude vazia chegava como null ao setter e estourava 500.
 */
#[CoversClass(TenantController::class)]
#[CoversClass(SedeType::class)]
#[CoversClass(Sede::class)]
final class SedeCoordenadasValidacaoControllerTest extends JusPrimeWebTestCase
{
    private const LAT_ANTIGA  = '-22.90680000';
    private const LNG_ANTIGA  = '-43.17290000';
    private const RAIO_ANTIGO = 100;

    /** CSRF stateless (token_id submit) confere a mesma origem. */
    private const ORIGEM = ['HTTP_ORIGIN' => 'http://localhost'];

    /** @return iterable<string, array{0: array<string, string>, 1: string}> campos alterados → mensagem esperada */
    public static function valoresInvalidos(): iterable
    {
        yield 'latitude vazia'           => [['latitude' => ''], 'Latitude é obrigatória.'];
        yield 'longitude vazia'          => [['longitude' => ''], 'Longitude é obrigatória.'];
        yield 'latitude acima de 90'     => [['latitude' => '90.5'], 'Latitude deve estar entre -90 e 90.'];
        yield 'latitude abaixo de -90'   => [['latitude' => '-91'], 'Latitude deve estar entre -90 e 90.'];
        yield 'longitude acima de 180'   => [['longitude' => '180.1'], 'Longitude deve estar entre -180 e 180.'];
        yield 'longitude abaixo de -180' => [['longitude' => '-181'], 'Longitude deve estar entre -180 e 180.'];
        yield 'latitude não numérica'    => [['latitude' => 'abc'], 'Latitude deve ser um número.'];
        yield 'longitude não numérica'   => [['longitude' => 'abc'], 'Longitude deve ser um número.'];
        yield 'latitude com vírgula'     => [['latitude' => '-22,9068'], 'Latitude deve ser um número.'];
        yield 'raio abaixo de 10'        => [['raioPermitido' => '5'], 'Raio permitido deve estar entre 10 e 500 metros.'];
        yield 'raio acima de 500'        => [['raioPermitido' => '600'], 'Raio permitido deve estar entre 10 e 500 metros.'];
        yield 'raio não numérico'        => [['raioPermitido' => 'abc'], 'Raio permitido deve ser um número.'];
        yield 'raio fracionado'          => [['raioPermitido' => '10.5'], 'Raio permitido deve ser um número inteiro de metros.'];
    }

    /** @param array<string, string> $alteracoes */
    #[DataProvider('valoresInvalidos')]
    #[TestDox('Editar sede com $_dataName: erro no campo, nada gravado, cerca antiga preservada')]
    public function testEdicaoInvalidaMostraErroENaoGrava(array $alteracoes, string $mensagem): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $sede   = $this->criarSede($tenant);
        $this->logarComTenant($client, $this->criarAdmin($tenant), $tenant);

        $campos = array_merge($this->camposValidos($client, $tenant), $alteracoes);
        $client->request('POST', "/tenant/{$tenant->getId()}/sedes/{$sede->getId()}/edit", ['sede' => $campos], [], self::ORIGEM);

        self::assertContains($client->getResponse()->getStatusCode(), [200, 422], 'form inválido renderiza a tela de novo, sem 500');
        self::assertStringContainsString($mensagem, (string) $client->getResponse()->getContent());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $gravada = $em->find(Sede::class, $sede->getId());
        self::assertNotNull($gravada);
        self::assertSame(self::LAT_ANTIGA, $gravada->getLatitude());
        self::assertSame(self::LNG_ANTIGA, $gravada->getLongitude());
        self::assertSame(self::RAIO_ANTIGO, $gravada->getRaioPermitido());
        self::assertSame('Sede Antiga', $gravada->getNome());
    }

    /** @param array<string, string> $alteracoes */
    #[DataProvider('valoresInvalidos')]
    #[TestDox('Criar sede com $_dataName: erro no campo e nenhuma sede gravada')]
    public function testCriacaoInvalidaMostraErroENaoGrava(array $alteracoes, string $mensagem): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarAdmin($tenant), $tenant);

        $campos = array_merge($this->camposValidos($client, $tenant), $alteracoes);
        $client->request('POST', "/tenant/{$tenant->getId()}/sedes", ['sede' => $campos], [], self::ORIGEM);

        self::assertContains($client->getResponse()->getStatusCode(), [200, 422], 'form inválido renderiza a tela de novo, sem 500');
        self::assertStringContainsString($mensagem, (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->contarSedes($tenant));
    }

    #[TestDox('Editar sede com valores válidos grava a nova cerca e redireciona')]
    public function testEdicaoValidaGrava(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $sede   = $this->criarSede($tenant);
        $this->logarComTenant($client, $this->criarAdmin($tenant), $tenant);

        $campos = array_merge($this->camposValidos($client, $tenant), [
            'nome'          => 'Sede Nova',
            'latitude'      => '-23.55052000',
            'longitude'     => '-46.63330800',
            'raioPermitido' => '200',
        ]);
        $client->request('POST', "/tenant/{$tenant->getId()}/sedes/{$sede->getId()}/edit", ['sede' => $campos], [], self::ORIGEM);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/sedes");

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $gravada = $em->find(Sede::class, $sede->getId());
        self::assertNotNull($gravada);
        self::assertSame('Sede Nova', $gravada->getNome());
        self::assertSame('-23.55052000', $gravada->getLatitude());
        self::assertSame('-46.63330800', $gravada->getLongitude());
        self::assertSame(200, $gravada->getRaioPermitido());
    }

    #[TestDox('Criar sede com valores válidos grava e redireciona')]
    public function testCriacaoValidaGrava(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->logarComTenant($client, $this->criarAdmin($tenant), $tenant);

        $client->request('POST', "/tenant/{$tenant->getId()}/sedes", ['sede' => $this->camposValidos($client, $tenant)], [], self::ORIGEM);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}/sedes");
        self::assertSame(1, $this->contarSedes($tenant));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Abre a tela (pega o token do form) e devolve um conjunto válido de campos.
     *
     * @return array<string, string>
     */
    private function camposValidos(KernelBrowser $client, Tenant $tenant): array
    {
        $crawler = $client->request('GET', "/tenant/{$tenant->getId()}/sedes");
        self::assertResponseIsSuccessful();

        return [
            'nome'          => 'Sede Antiga',
            'latitude'      => self::LAT_ANTIGA,
            'longitude'     => self::LNG_ANTIGA,
            'raioPermitido' => (string) self::RAIO_ANTIGO,
            '_token'        => (string) $crawler->filter('input[name="sede[_token]"]')->attr('value'),
        ];
    }

    private function contarSedes(Tenant $tenant): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Sede::class)->count(['tenant' => $tenant->getId()]);
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant SEDE ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarSede(Tenant $tenant): Sede
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $sede = new Sede();
        $sede->setNome('Sede Antiga');
        $sede->setLatitude(self::LAT_ANTIGA);
        $sede->setLongitude(self::LNG_ANTIGA);
        $sede->setRaioPermitido(self::RAIO_ANTIGO);
        $sede->setTimezone('America/Sao_Paulo');
        $sede->setTenant($tenant);
        $em->persist($sede);
        $em->flush();

        return $sede;
    }

    /** Admin do escritório: vínculo ativo + TenantRole isSystem (canAdminister=true em qualquer módulo). */
    private function criarAdmin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('admin_sede_' . uniqid() . '@test.com');
        $user->setFullName('Admin Sede');
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
}
