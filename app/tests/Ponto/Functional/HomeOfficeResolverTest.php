<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Repository\HomeOfficeConfigRepository;
use App\Ponto\Service\HomeOfficeResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cobre o resolver do bypass de home office contra o banco real (query com filtro de tenant
 * explícito + lógica de dia da semana / vigência / datas avulsas / timezone). Datas de referência
 * (dia da semana ISO): 2026-07-06 = segunda(1), 2026-07-07 = terça(2), 2026-07-08 = quarta(3).
 */
#[CoversClass(HomeOfficeResolver::class)]
final class HomeOfficeResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private HomeOfficeResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var HomeOfficeConfigRepository $repo */
        $repo = $this->em->getRepository(HomeOfficeConfig::class);
        $this->resolver = new HomeOfficeResolver($repo);
    }

    #[TestDox('Sem config de home office → não liberado')]
    public function testSemConfigNaoLibera(): void
    {
        $user = $this->criarUser();
        $tenant = $this->criarTenant();

        self::assertFalse($this->resolver->estaLiberado($user, $tenant, $this->instante('2026-07-06')));
    }

    #[TestDox('Config inativa → não liberado mesmo no dia certo')]
    public function testConfigInativaNaoLibera(): void
    {
        $user = $this->criarUser();
        $tenant = $this->criarTenant();
        $this->criarConfig($user, $tenant, dias: [1], ativo: false);

        self::assertFalse($this->resolver->estaLiberado($user, $tenant, $this->instante('2026-07-06')));
    }

    #[TestDox('Dia da semana marcado → liberado; dia não marcado → não liberado')]
    public function testDiaDaSemana(): void
    {
        $user = $this->criarUser();
        $tenant = $this->criarTenant();
        $this->criarConfig($user, $tenant, dias: [1]); // só segunda

        self::assertTrue($this->resolver->estaLiberado($user, $tenant, $this->instante('2026-07-06')));  // segunda
        self::assertFalse($this->resolver->estaLiberado($user, $tenant, $this->instante('2026-07-07'))); // terça
    }

    #[TestDox('Vigência delimita a recorrência semanal (antes/dentro/depois)')]
    public function testVigenciaDelimitaRecorrencia(): void
    {
        $user = $this->criarUser();
        $tenant = $this->criarTenant();
        $this->criarConfig($user, $tenant, dias: [1], vigIni: '2026-07-01', vigFim: '2026-07-31');

        self::assertFalse($this->resolver->estaLiberado($user, $tenant, $this->instante('2026-06-29'))); // segunda antes
        self::assertTrue($this->resolver->estaLiberado($user, $tenant, $this->instante('2026-07-06')));  // segunda dentro
        self::assertFalse($this->resolver->estaLiberado($user, $tenant, $this->instante('2026-08-03'))); // segunda depois
    }

    #[TestDox('Data avulsa libera por si — inclusive fora da vigência da recorrência')]
    public function testDataAvulsaIndependeDaVigencia(): void
    {
        $user = $this->criarUser();
        $tenant = $this->criarTenant();
        // Recorrência só às segundas até 02/07; a data avulsa 20/07 (segunda) está fora da vigência.
        $this->criarConfig($user, $tenant, dias: [1], datas: ['2026-07-20'], vigIni: '2026-07-01', vigFim: '2026-07-02');

        self::assertTrue($this->resolver->estaLiberado($user, $tenant, $this->instante('2026-07-20')));
    }

    #[TestDox('Data avulsa em dia da semana não recorrente também libera')]
    public function testDataAvulsaSemRecorrencia(): void
    {
        $user = $this->criarUser();
        $tenant = $this->criarTenant();
        $this->criarConfig($user, $tenant, dias: [], datas: ['2026-07-08']); // quarta avulsa, sem recorrência

        self::assertTrue($this->resolver->estaLiberado($user, $tenant, $this->instante('2026-07-08')));
        self::assertFalse($this->resolver->estaLiberado($user, $tenant, $this->instante('2026-07-07')));
    }

    #[TestDox('Config de um tenant não libera no outro tenant (isolamento)')]
    public function testIsolamentoCrossTenant(): void
    {
        $user = $this->criarUser();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $this->criarConfig($user, $tenantA, dias: [1]);

        self::assertTrue($this->resolver->estaLiberado($user, $tenantA, $this->instante('2026-07-06')));
        self::assertFalse($this->resolver->estaLiberado($user, $tenantB, $this->instante('2026-07-06')));
    }

    #[TestDox('O dia é resolvido no timezone da sede do tenant (evita bug de virada de dia)')]
    public function testNormalizaTimezoneDaSede(): void
    {
        $user = $this->criarUser();
        $tenant = $this->criarTenant();
        $this->adicionarSede($tenant, 'America/Sao_Paulo');
        $this->criarConfig($user, $tenant, dias: [], datas: ['2026-07-06']);

        // 2026-07-07 01:00 UTC == 2026-07-06 22:00 em São Paulo (UTC-3) → cai na data avulsa 06/07.
        $instanteUtc = new \DateTimeImmutable('2026-07-07 01:00:00', new \DateTimeZone('UTC'));

        self::assertTrue($this->resolver->estaLiberado($user, $tenant, $instanteUtc));
    }

    // ----------------------------------------------------------------- helpers

    private function instante(string $data): \DateTimeImmutable
    {
        // Meio-dia no fuso de referência padrão evita cruzar a meia-noite na normalização.
        return new \DateTimeImmutable($data . ' 12:00:00', new \DateTimeZone('America/Sao_Paulo'));
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant HO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('ho_' . uniqid() . '@test.com');
        $user->setFullName('User Home Office');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function adicionarSede(Tenant $tenant, string $timezone): Sede
    {
        $sede = new Sede();
        $sede->setNome('Sede ' . uniqid());
        $sede->setLatitude('-23.55000000');
        $sede->setLongitude('-46.63000000');
        $sede->setRaioPermitido(100);
        $sede->setTimezone($timezone);
        $sede->setTenant($tenant);
        $this->em->persist($sede);
        $this->em->flush();

        return $sede;
    }

    /**
     * @param int[]    $dias
     * @param string[] $datas
     */
    private function criarConfig(
        User $user,
        Tenant $tenant,
        array $dias,
        array $datas = [],
        ?string $vigIni = null,
        ?string $vigFim = null,
        bool $ativo = true,
    ): HomeOfficeConfig {
        $config = new HomeOfficeConfig();
        $config->setUser($user);
        $config->setTenant($tenant);
        $config->setDiasSemana($dias);
        $config->setDatasAvulsas($datas);
        $config->setVigenciaInicio($vigIni !== null ? new \DateTimeImmutable($vigIni) : null);
        $config->setVigenciaFim($vigFim !== null ? new \DateTimeImmutable($vigFim) : null);
        $config->setAtivo($ativo);
        $this->em->persist($config);
        $this->em->flush();

        return $config;
    }
}
