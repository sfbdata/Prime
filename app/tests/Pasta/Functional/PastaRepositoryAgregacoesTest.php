<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(PastaRepository::class)]
#[Group('pasta')]
final class PastaRepositoryAgregacoesTest extends KernelTestCase
{
    use Factories;

    private PastaRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(PastaRepository::class);
    }

    #[TestDox('countAtivas retorna só as ativas do tenant-alvo, excluindo arquivadas')]
    public function testCountAtivasRetornaSoAtivasDoTenantAlvo(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();

        PastaFactory::createMany(2, ['tenant' => $tenantA]);
        PastaFactory::createOne(['tenant' => $tenantA, 'situacao' => Pasta::SITUACAO_ARQUIVADA]);
        PastaFactory::createOne(['tenant' => $tenantB]);

        self::assertSame(2, $this->repo->countAtivas($tenantA));
    }

    #[TestDox('countAtivas não vaza pastas do tenant B')]
    public function testCountAtivasIsolaTenant(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();

        PastaFactory::createOne(['tenant' => $tenantA]);
        PastaFactory::createMany(3, ['tenant' => $tenantB]);

        self::assertSame(1, $this->repo->countAtivas($tenantA));
    }

    #[TestDox('countUrgentes retorna só as urgentes do tenant-alvo')]
    public function testCountUrgentesRetornaSoUrgentesDoTenantAlvo(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();

        PastaFactory::createOne(['tenant' => $tenantA, 'prioridade' => PrioridadePasta::Urgente]);
        PastaFactory::createMany(2, ['tenant' => $tenantA]);
        PastaFactory::createOne(['tenant' => $tenantB, 'prioridade' => PrioridadePasta::Urgente]);

        self::assertSame(1, $this->repo->countUrgentes($tenantA));
    }

    #[TestDox('countUrgentes retorna zero quando não há pastas urgentes')]
    public function testCountUrgentesRetornaZeroSemUrgentes(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        PastaFactory::createMany(2, ['tenant' => $tenant]);

        self::assertSame(0, $this->repo->countUrgentes($tenant));
    }

    #[TestDox('countPorResponsavel agrupa por responsável, excluindo pastas sem responsável')]
    public function testCountPorResponsavelAgrupaPorResponsavel(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $u1     = UserFactory::createOne()->_real();
        $u2     = UserFactory::createOne()->_real();

        PastaFactory::createMany(2, ['tenant' => $tenant, 'responsavel' => $u1]);
        PastaFactory::createOne(['tenant' => $tenant, 'responsavel' => $u2]);
        PastaFactory::createOne(['tenant' => $tenant]);

        $resultado = $this->repo->countPorResponsavel($tenant);

        self::assertSame(2, $resultado[$u1->getId()]);
        self::assertSame(1, $resultado[$u2->getId()]);
        self::assertCount(2, $resultado);
    }

    #[TestDox('countPorResponsavel não vaza pastas do tenant B')]
    public function testCountPorResponsavelIsolaTenant(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $u1      = UserFactory::createOne()->_real();

        PastaFactory::createOne(['tenant' => $tenantA, 'responsavel' => $u1]);
        PastaFactory::createMany(2, ['tenant' => $tenantB, 'responsavel' => $u1]);

        $resultado = $this->repo->countPorResponsavel($tenantA);

        self::assertSame(1, $resultado[$u1->getId()]);
        self::assertCount(1, $resultado);
    }

    #[TestDox('countAtivasPorResponsavel exclui pastas arquivadas do responsável')]
    public function testCountAtivasPorResponsavelExcluiArquivadas(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $u1     = UserFactory::createOne()->_real();

        PastaFactory::createMany(2, ['tenant' => $tenant, 'responsavel' => $u1]);
        PastaFactory::createOne(['tenant' => $tenant, 'responsavel' => $u1, 'situacao' => Pasta::SITUACAO_ARQUIVADA]);

        $resultado = $this->repo->countAtivasPorResponsavel($tenant);

        self::assertSame(2, $resultado[$u1->getId()]);
        self::assertCount(1, $resultado);
    }

    #[TestDox('countAtivasPorResponsavel não vaza pastas do tenant B')]
    public function testCountAtivasPorResponsavelIsolaTenant(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $u1      = UserFactory::createOne()->_real();

        PastaFactory::createOne(['tenant' => $tenantA, 'responsavel' => $u1]);
        PastaFactory::createMany(3, ['tenant' => $tenantB, 'responsavel' => $u1]);

        $resultado = $this->repo->countAtivasPorResponsavel($tenantA);

        self::assertSame(1, $resultado[$u1->getId()]);
        self::assertCount(1, $resultado);
    }
}
