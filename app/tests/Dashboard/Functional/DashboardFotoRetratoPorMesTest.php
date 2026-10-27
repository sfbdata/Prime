<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Repository\DashboardFotoRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/** `retratoPorMes` contra o banco: a última foto de CADA pessoa no mês, tenant isolado, NULL ignorado. */
#[CoversClass(DashboardFotoRepository::class)]
#[Group('dashboard')]
final class DashboardFotoRetratoPorMesTest extends KernelTestCase
{
    use Factories;

    /** @return array<string, int|null> */
    private static function foto(int $ativas, ?int $urgentes): array
    {
        return ['metas_ativas' => $ativas, 'demandas_ativas' => 0, 'metas_vencidas' => 0, 'prazos_proximos' => 0, 'metas_em_revisao' => null, 'pastas_urgentes' => $urgentes, 'metas_total' => null];
    }

    #[TestDox('Último dia POR PESSOA no mês (foto parcial não subestima), outro escritório fora, coluna NULL ignorada, mês sem foto ausente')]
    public function testRetratoPorMes(): void
    {
        $repo   = static::getContainer()->get(DashboardFotoRepository::class);
        $tenant = TenantFactory::createOne()->_real();
        $outro  = TenantFactory::createOne()->_real();
        $ana    = (int) UserFactory::createOne()->_real()->getId();
        $beto   = (int) UserFactory::createOne()->_real()->getId();
        $d      = static fn (string $dia): \DateTimeImmutable => new \DateTimeImmutable($dia);

        // Maio: Ana fotografada em 10 e 28; Beto só no dia 20 (o último dia global é 28, sem Beto).
        $repo->gravar($tenant, $d('2026-05-10'), [$ana => self::foto(1, 1)]);
        $repo->gravar($tenant, $d('2026-05-28'), [$ana => self::foto(5, 2)]);
        $repo->gravar($tenant, $d('2026-05-20'), [$beto => self::foto(3, 4)]);
        // Junho: foto antiga, sem pastas_urgentes (NULL), só metas_ativas.
        $repo->gravar($tenant, $d('2026-06-15'), [$ana => self::foto(7, null)]);
        // Outro escritório, mesma pessoa e mês.
        $repo->gravar($outro, $d('2026-05-29'), [$ana => self::foto(99, 99)]);

        $de  = $d('2026-01-01');
        $ate = $d('2026-12-31');

        self::assertSame(
            ['2026-05' => ['valor' => 8, 'ativas' => 8], '2026-06' => ['valor' => 7, 'ativas' => 7]],
            $repo->retratoPorMes($tenant, [$ana, $beto], 'metas_ativas', $de, $ate),
            'maio = Ana(28) 5 + Beto(20) 3',
        );
        self::assertSame(
            ['2026-05' => ['valor' => 6, 'ativas' => 8]],
            $repo->retratoPorMes($tenant, [$ana, $beto], 'pastas_urgentes', $de, $ate),
            'junho com a coluna NULL fica fora; nunca vira zero',
        );
        self::assertSame(['2026-05' => ['valor' => 5, 'ativas' => 5]], $repo->retratoPorMes($tenant, [$ana], 'metas_ativas', $d('2026-05-01'), $d('2026-05-31')), 'só o universo e o intervalo pedidos');
        self::assertSame([], $repo->retratoPorMes($tenant, [$ana], 'metas_ativas', $d('2026-02-01'), $d('2026-02-28')), 'mês sem foto');
        self::assertSame([], $repo->retratoPorMes($tenant, [], 'metas_ativas', $de, $ate));
    }
}
