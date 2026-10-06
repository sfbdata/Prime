<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Isolamento das análises por escritório em DUAS camadas, provadas separadamente:
 *   · o guarda do REPOSITÓRIO, com o TenantFilter global DESLIGADO — é o que vale no worker e no
 *     console, onde não há sessão (mesma razão do `PastaChecklistModeloRepositoryTest`);
 *   · o TenantFilter LIGADO sobre a entidade — prova que `AnaliseDeInteligencia` é TenantAware de
 *     verdade, ou seja, que uma consulta genérica numa request também não vaza.
 */
#[CoversClass(AnaliseDeInteligenciaRepository::class)]
final class InteligenciaTenantFilterTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function desligarTenantFilter(): void
    {
        $filtros = $this->em()->getFilters();
        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }

        self::assertFalse($filtros->isEnabled('tenant'), 'o filtro global precisa estar OFF para este teste valer');
    }

    private function repositorio(): AnaliseDeInteligenciaRepository
    {
        return static::getContainer()->get(AnaliseDeInteligenciaRepository::class);
    }

    #[TestDox('listarPorAlvo não devolve análise de outro escritório, mesmo com alvo_id igual e o filtro global desligado')]
    public function testListarPorAlvoNaoVaza(): void
    {
        self::bootKernel();
        $this->desligarTenantFilter();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $pastaA = $this->criarPasta($tenantA);
        $minha = $this->criarAnalisePendente($tenantA, $userA, $pastaA);
        // O vizinho tem uma análise apontando para o MESMO alvo_id (ids de pasta são globais).
        $doVizinho = $this->criarAnalisePendente($tenantB, $userB, $pastaA);

        $deA = $this->repositorio()->listarPorAlvo($tenantA, AnaliseDeInteligencia::ALVO_PASTA, (int) $pastaA->getId());
        $deB = $this->repositorio()->listarPorAlvo($tenantB, AnaliseDeInteligencia::ALVO_PASTA, (int) $pastaA->getId());

        self::assertSame([$minha->getId()], array_map(static fn (AnaliseDeInteligencia $a): ?int => $a->getId(), $deA));
        self::assertSame([$doVizinho->getId()], array_map(static fn (AnaliseDeInteligencia $a): ?int => $a->getId(), $deB));
    }

    #[TestDox('findOneDoTenant / findOneDoTenantEAlvo devolvem null para id de outro escritório ou de outra pasta')]
    public function testBuscaPorIdNaoAlcancaOVizinho(): void
    {
        self::bootKernel();
        $this->desligarTenantFilter();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $pastaA1 = $this->criarPasta($tenantA);
        $pastaA2 = $this->criarPasta($tenantA);
        $doVizinho = $this->criarAnalisePendente($tenantB, $userB, $this->criarPasta($tenantB));
        $daIrma = $this->criarAnalisePendente($tenantA, $userA, $pastaA2);
        $repo = $this->repositorio();

        self::assertNull($repo->findOneDoTenant((int) $doVizinho->getId(), $tenantA));
        self::assertNotNull($repo->findOneDoTenant((int) $doVizinho->getId(), $tenantB), 'para o dono, o mesmo id continua achável');

        self::assertNull($repo->findOneDoTenantEAlvo((int) $daIrma->getId(), $tenantA, AnaliseDeInteligencia::ALVO_PASTA, (int) $pastaA1->getId()));
        self::assertNotNull($repo->findOneDoTenantEAlvo((int) $daIrma->getId(), $tenantA, AnaliseDeInteligencia::ALVO_PASTA, (int) $pastaA2->getId()));
    }

    #[TestDox('contarDesde e findPendenteDoAlvo são por escritório')]
    public function testContagemEPendentePorTenant(): void
    {
        self::bootKernel();
        $this->desligarTenantFilter();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $pastaA = $this->criarPasta($tenantA);
        $this->criarAnalisePendente($tenantA, $userA, $pastaA);
        $this->criarAnalisePendente($tenantB, $userB, $pastaA);
        $this->criarAnalisePendente($tenantB, $userB, $pastaA);
        $repo = $this->repositorio();
        $ontem = new \DateTimeImmutable('-1 day');

        self::assertSame(1, $repo->contarDesde($tenantA, $ontem));
        self::assertSame(2, $repo->contarDesde($tenantB, $ontem));
        self::assertSame($tenantA->getId(), $repo->findPendenteDoAlvo($tenantA, AnaliseDeInteligencia::ALVO_PASTA, (int) $pastaA->getId())?->getTenant()?->getId());
    }

    #[TestDox('com o TenantFilter LIGADO para A, uma consulta genérica também não enxerga a linha de B (a entidade é TenantAware)')]
    public function testFiltroGlobalCobreAEntidade(): void
    {
        self::bootKernel();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $pastaA = $this->criarPasta($tenantA);
        $minha = $this->criarAnalisePendente($tenantA, $userA, $pastaA);
        $this->criarAnalisePendente($tenantB, $userB, $pastaA);

        $this->em()->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenantA->getId(), Types::INTEGER);
        try {
            $vistas = $this->em()->getRepository(AnaliseDeInteligencia::class)->findBy(['alvoId' => $pastaA->getId()]);
        } finally {
            $this->em()->getFilters()->disable('tenant');
        }

        self::assertSame([$minha->getId()], array_map(static fn (AnaliseDeInteligencia $a): ?int => $a->getId(), $vistas));
    }
}
