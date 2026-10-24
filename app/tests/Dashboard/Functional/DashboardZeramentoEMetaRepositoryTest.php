<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Entity\DashboardMetaPastas;
use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Repository\DashboardMetaPastasRepository;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * As tabelas novas do Dashboard (PR3) contra o banco de verdade: zeramento de coluna (DASH-014) e
 * meta de pastas (DASH-016), com o isolamento entre escritórios.
 */
#[CoversClass(DashboardZeramentoRepository::class)]
#[CoversClass(DashboardMetaPastasRepository::class)]
#[Group('dashboard')]
final class DashboardZeramentoEMetaRepositoryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Zeramento: lista só os ativos do escritório, do mais novo ao mais antigo; o de outro escritório não aparece nem por id')]
    public function testZeramentosDoEscritorio(): void
    {
        $repo   = static::getContainer()->get(DashboardZeramentoRepository::class);
        $tenant = TenantFactory::createOne()->_real();
        $outro  = TenantFactory::createOne()->_real();
        $master = UserFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();

        $antigo  = new DashboardZeramento($tenant, 'relatorio', null, new \DateTimeImmutable('2026-10-01'), $master, new \DateTimeImmutable('2026-10-01 09:00'));
        $novo    = new DashboardZeramento($tenant, 'pastas_concluidas', $ana, new \DateTimeImmutable('2026-10-10'), $master, new \DateTimeImmutable('2026-10-10 09:00'));
        $desfeito = new DashboardZeramento($tenant, 'relatorio', $ana, new \DateTimeImmutable('2026-10-05'), $master);
        $desfeito->desfazer($master, new \DateTimeImmutable('2026-10-06'));
        $alheio  = new DashboardZeramento($outro, 'relatorio', null, new \DateTimeImmutable('2026-10-01'), $master);
        foreach ([$antigo, $novo, $desfeito, $alheio] as $z) {
            $repo->salvar($z);
        }
        $this->em->flush();
        $this->em->clear();

        $ids = array_map(static fn (DashboardZeramento $z): ?int => $z->getId(), $repo->listarAtivos($this->em->find($tenant::class, $tenant->getId())));

        self::assertSame([$novo->getId(), $antigo->getId()], $ids);
        $tenantGerenciado = $this->em->find($tenant::class, $tenant->getId());
        self::assertNull($repo->buscarDoEscritorio($tenantGerenciado, (int) $alheio->getId()), 'IDOR: o de outro escritório volta nulo');
        $achado = $repo->buscarDoEscritorio($tenantGerenciado, (int) $novo->getId());
        self::assertNotNull($achado);
        self::assertSame($ana->getId(), $achado->getUsuario()?->getId());
    }

    #[TestDox('Meta de pastas: vale a de vigência mais recente até o mês pedido; no mesmo mês, a última gravada; antes da primeira, nenhuma; outro escritório não vaza')]
    public function testMetaVigente(): void
    {
        $repo   = static::getContainer()->get(DashboardMetaPastasRepository::class);
        $tenant = TenantFactory::createOne()->_real();
        $outro  = TenantFactory::createOne()->_real();
        $master = UserFactory::createOne()->_real();

        $repo->salvar(new DashboardMetaPastas($tenant, new \DateTimeImmutable('2026-08-15'), 20, $master, new \DateTimeImmutable('2026-08-15 10:00')));
        $repo->salvar(new DashboardMetaPastas($tenant, new \DateTimeImmutable('2026-10-01'), 25, $master, new \DateTimeImmutable('2026-10-01 10:00')));
        $repo->salvar(new DashboardMetaPastas($tenant, new \DateTimeImmutable('2026-10-20'), 30, $master, new \DateTimeImmutable('2026-10-20 10:00')));
        $repo->salvar(new DashboardMetaPastas($outro, new \DateTimeImmutable('2026-01-01'), 99, $master));
        $this->em->flush();

        self::assertNull($repo->buscarVigenteEm($tenant, new \DateTimeImmutable('2026-07-31')));
        self::assertSame(20, $repo->buscarVigenteEm($tenant, new \DateTimeImmutable('2026-09-30'))?->getValor(), 'vale até ser trocada');
        self::assertSame(30, $repo->buscarVigenteEm($tenant, new \DateTimeImmutable('2026-10-05'))?->getValor(), 'trocada no mesmo mês: a última');
        self::assertSame(30, $repo->buscarVigenteEm($tenant, new \DateTimeImmutable('2027-03-01'))?->getValor());
        self::assertSame(99, $repo->buscarVigenteEm($outro, new \DateTimeImmutable('2026-10-05'))?->getValor());
    }
}
