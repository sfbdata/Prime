<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(LancamentoHorasPagasRepository::class)]
final class LancamentoHorasPagasRepositoryTest extends JusPrimeWebTestCase
{
    #[TestDox('somarPorCompetencia soma varios lancamentos do mesmo mes, com sinal')]
    public function testSomaVariosLancamentosDoMesmoMes(): void
    {
        static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);
        $alvo   = $this->criarUsuario($tenant);

        $this->gravarLancamento($tenant, $alvo, $admin, 2026, 8, -6000);
        $this->gravarLancamento($tenant, $alvo, $admin, 2026, 8, 480);
        $this->gravarLancamento($tenant, $alvo, $admin, 2026, 9, 120); // outro mes, nao pode entrar

        $repo = static::getContainer()->get(LancamentoHorasPagasRepository::class);

        self::assertSame(-5520, $repo->somarPorCompetencia($alvo, $tenant, 2026, 8));
    }

    #[TestDox('somarPorCompetencia retorna 0 quando nao ha lancamento')]
    public function testSemLancamentoRetornaZero(): void
    {
        static::createClient();
        $tenant = $this->criarTenant();
        $alvo   = $this->criarUsuario($tenant);

        $repo = static::getContainer()->get(LancamentoHorasPagasRepository::class);

        self::assertSame(0, $repo->somarPorCompetencia($alvo, $tenant, 2026, 8));
    }

    #[TestDox('somarPorCompetencia nao enxerga lancamento de outro tenant')]
    public function testIsolamentoEntreTenants(): void
    {
        static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $admin   = $this->criarAdmin($tenantA);
        $alvo    = $this->criarUsuario($tenantA);

        // mesmo colaborador, lancamento gravado sob o tenant B: nao pode vazar para o A
        $this->gravarLancamento($tenantB, $alvo, $admin, 2026, 8, 999);

        $repo = static::getContainer()->get(LancamentoHorasPagasRepository::class);

        self::assertSame(0, $repo->somarPorCompetencia($alvo, $tenantA, 2026, 8));
    }

    #[TestDox('buscarDoTenant devolve null para lancamento de outro tenant')]
    public function testBuscarDoTenantNaoVazaEntreEscritorios(): void
    {
        static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $admin   = $this->criarAdmin($tenantB);
        $alvo    = $this->criarUsuario($tenantB);

        $lancamento = $this->gravarLancamento($tenantB, $alvo, $admin, 2026, 8, 300);

        $repo = static::getContainer()->get(LancamentoHorasPagasRepository::class);

        self::assertNull($repo->buscarDoTenant((int) $lancamento->getId(), $tenantA));
        self::assertNotNull($repo->buscarDoTenant((int) $lancamento->getId(), $tenantB));
    }

    private function gravarLancamento(
        Tenant $tenant,
        User $alvo,
        User $autor,
        int $ano,
        int $mes,
        int $minutos,
    ): LancamentoHorasPagas {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($tenant);
        $lancamento->setUser($alvo);
        $lancamento->setAno($ano);
        $lancamento->setMes($mes);
        $lancamento->setMinutos($minutos);
        $lancamento->setMotivo('teste');
        $lancamento->setCriadoPor($autor);
        $lancamento->setCriadoEm(new \DateTimeImmutable());

        $em->persist($lancamento);
        $em->flush();

        return $lancamento;
    }

    // ----------------------------------------------------------------- helpers
    // JusPrimeWebTestCase não expõe criarTenant()/criarAdmin()/criarUsuario() — cada
    // teste funcional de Ponto define seus próprios, copiados de
    // PontoManualCsrfControllerTest.php (mesmas assinaturas usadas aqui).

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant HORAS PAGAS ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarAdmin(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('admin_' . uniqid() . '@test.com');
        $user->setFullName('Admin Horas Pagas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('alvo_' . uniqid() . '@test.com');
        $user->setFullName('Alvo Horas Pagas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }
}
