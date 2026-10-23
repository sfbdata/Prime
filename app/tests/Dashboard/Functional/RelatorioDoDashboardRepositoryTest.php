<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Coluna "Relatório" (spec dashboard-parte-1, item 2): registros por autor nas três abas, contra o
 * banco de verdade, com o cartão do sistema fora, resposta dentro, edição sem contar de novo e o
 * isolamento entre escritórios.
 */
#[CoversClass(MetricasExtrasDoDashboardRepository::class)]
#[Group('dashboard')]
final class RelatorioDoDashboardRepositoryTest extends KernelTestCase
{
    use Factories;

    private MetricasExtrasDoDashboardRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(MetricasExtrasDoDashboardRepository::class);
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param class-string<PastaMensagem|PastaObservacaoFinanceira|PastaObservacaoDetalhes> $classe */
    private function registro(string $classe, Pasta $pasta, User $autor, string $criadoEm, ?object $respostaA = null): object
    {
        $r = new $classe();
        $r->setPasta($pasta);
        $r->setAutor($autor);
        $r->setTenant($pasta->getTenant());
        $r->setConteudo('Registro de teste');
        if ($respostaA !== null) {
            $r->setRespostaA($respostaA);
        }
        (new \ReflectionProperty($classe, 'criadaEm'))->setValue($r, new \DateTimeImmutable($criadoEm));
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function pasta(Tenant $tenant): Pasta
    {
        return PastaFactory::createOne(['tenant' => $tenant])->_real();
    }

    #[TestDox('Soma as três abas por autor; resposta conta; edição não conta de novo; cartão do sistema fica fora')]
    public function testSomaAsTresAbas(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $bruno  = UserFactory::createOne()->_real();
        $pasta  = $this->pasta($tenant);

        $msg = $this->registro(PastaMensagem::class, $pasta, $ana, '2026-10-05 10:00');
        $this->registro(PastaMensagem::class, $pasta, $bruno, '2026-10-05 11:00', $msg); // resposta: conta para Bruno
        $sistema = $this->registro(PastaMensagem::class, $pasta, $ana, '2026-10-06 10:00');
        $sistema->setSistema('andamento');
        $this->registro(PastaObservacaoFinanceira::class, $pasta, $ana, '2026-10-07 10:00');
        $editada = $this->registro(PastaObservacaoDetalhes::class, $pasta, $ana, '2026-10-08 10:00');
        $editada->setEditadaEm(new \DateTimeImmutable('2026-10-09 10:00'));
        $this->em->flush();

        $mapa = $this->repo->contarRegistrosPorAutor($tenant, ['data_de' => '2026-10-01', 'data_ate' => '2026-10-31']);

        self::assertSame(3, $mapa[$ana->getId()] ?? 0, 'mensagem + financeiro + detalhes (editada uma vez só)');
        self::assertSame(1, $mapa[$bruno->getId()] ?? 0);
    }

    #[TestDox('Período pela criação; outro escritório, pasta excluída e pasta oculta ficam fora')]
    public function testPeriodoEIsolamento(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $outro  = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $pasta  = $this->pasta($tenant);

        $this->registro(PastaMensagem::class, $pasta, $ana, '2026-09-30 23:59');               // antes do período
        $this->registro(PastaMensagem::class, $pasta, $ana, '2026-10-31 23:00');               // dentro
        $this->registro(PastaObservacaoFinanceira::class, $this->pasta($outro), $ana, '2026-10-10 10:00');
        $excluida = $this->pasta($tenant);
        $this->registro(PastaObservacaoDetalhes::class, $excluida, $ana, '2026-10-10 10:00');
        $excluida->marcarExcluida($ana, new \DateTimeImmutable());
        $oculta = $this->pasta($tenant);
        $this->registro(PastaMensagem::class, $oculta, $ana, '2026-10-10 10:00');
        $this->em->flush();

        $filtros = ['data_de' => '2026-10-01', 'data_ate' => '2026-10-31'];
        self::assertSame(1, $this->repo->contarRegistrosPorAutor($tenant, $filtros, [(int) $oculta->getId()])[$ana->getId()] ?? 0);
        self::assertSame(2, $this->repo->contarRegistrosPorAutor($tenant, $filtros)[$ana->getId()] ?? 0, 'sem cadeado, a oculta volta');
        self::assertSame(1, $this->repo->contarRegistrosPorAutor($outro, $filtros)[$ana->getId()] ?? 0);
    }
}
