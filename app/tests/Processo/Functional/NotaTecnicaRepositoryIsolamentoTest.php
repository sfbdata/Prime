<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\Entity\NotaTecnica;
use App\Tests\Pasta\Functional\CriaFixturesPushDaPastaTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O repositório filtra por escritório EXPLICITAMENTE — não depende do TenantFilter de sessão,
 * que aqui fica desligado de propósito (sem request, o listener não o liga). É o que vale para
 * a função Twig `notas_tecnicas_do_processo` e para o teor do Push: se o filtro explícito cair,
 * estes testes caem junto.
 */
#[CoversClass(NotaTecnicaRepository::class)]
#[Group('processo')]
final class NotaTecnicaRepositoryIsolamentoTest extends KernelTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';

    private function repositorio(): NotaTecnicaRepository
    {
        return static::getContainer()->get(NotaTecnicaRepository::class);
    }

    private function garantirFiltroDesligado(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
    }

    #[TestDox('listarPorProcesso: o processo de B consultado com o escritório A devolve vazio; com B, a nota')]
    public function testListarPorProcessoFiltraPorTenant(): void
    {
        self::bootKernel();
        $this->garantirFiltroDesligado();
        [, $tenantA]       = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $processoB         = $this->criarProcesso($tenantB, self::NUMERO);
        $notaB             = new NotaTecnica($tenantB, $processoB, $userB, '<p>De B</p>');
        $this->em()->persist($notaB);
        $this->em()->flush();

        self::assertSame([], $this->repositorio()->listarPorProcesso($processoB, $tenantA));
        self::assertCount(1, $this->repositorio()->listarPorProcesso($processoB, $tenantB));
    }

    #[TestDox('findOneByIdDoTenant: o id da nota de B não vira entidade para A')]
    public function testFindOneByIdDoTenantNaoAbreNotaAlheia(): void
    {
        self::bootKernel();
        $this->garantirFiltroDesligado();
        [, $tenantA]       = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $processoB         = $this->criarProcesso($tenantB, self::NUMERO);
        $notaB             = new NotaTecnica($tenantB, $processoB, $userB, '<p>De B</p>');
        $this->em()->persist($notaB);
        $this->em()->flush();

        self::assertNull($this->repositorio()->findOneByIdDoTenant((int) $notaB->getId(), $tenantA));
        self::assertSame($notaB->getId(), $this->repositorio()->findOneByIdDoTenant((int) $notaB->getId(), $tenantB)?->getId());
    }

    #[TestDox('listarPorPublicacao filtra por escritório e devolve a mais recente primeiro')]
    public function testListarPorPublicacaoFiltraEOrdena(): void
    {
        self::bootKernel();
        $this->garantirFiltroDesligado();
        [, $tenantA]       = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $processoB         = $this->criarProcesso($tenantB, self::NUMERO);
        $pubB              = $this->criarPublicacao($tenantB, '50000001', self::NUMERO, '2026-08-20', $processoB);

        $antiga = new NotaTecnica($tenantB, $processoB, $userB, '<p>Antiga</p>', $pubB);
        (new \ReflectionProperty(NotaTecnica::class, 'criadaEm'))->setValue($antiga, new \DateTimeImmutable('-10 minutes'));
        $nova = new NotaTecnica($tenantB, $processoB, $userB, '<p>Nova</p>', $pubB);
        $semGancho = new NotaTecnica($tenantB, $processoB, $userB, '<p>Só do processo</p>');
        $this->em()->persist($antiga);
        $this->em()->persist($nova);
        $this->em()->persist($semGancho);
        $this->em()->flush();

        self::assertSame([], $this->repositorio()->listarPorPublicacao($pubB, $tenantA));

        $deB = $this->repositorio()->listarPorPublicacao($pubB, $tenantB);
        self::assertSame(['<p>Nova</p>', '<p>Antiga</p>'], array_map(static fn (NotaTecnica $n): string => $n->getConteudo(), $deB));

        // Por processo entram as três, mais recente primeiro.
        $doProcesso = $this->repositorio()->listarPorProcesso($processoB, $tenantB);
        self::assertCount(3, $doProcesso);
        self::assertSame('<p>Antiga</p>', end($doProcesso)->getConteudo());
    }
}
