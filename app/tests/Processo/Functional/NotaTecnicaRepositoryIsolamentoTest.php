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

    #[TestDox('C6-FPN-01: listarPorPublicacao filtra por escritório e devolve em ordem cronológica (a nova no fim)')]
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
        self::assertSame(['<p>Antiga</p>', '<p>Nova</p>'], array_map(static fn (NotaTecnica $n): string => $n->getConteudo(), $deB));

        // Por processo entram as três, em ordem cronológica: a antiga abre a lista.
        $doProcesso = $this->repositorio()->listarPorProcesso($processoB, $tenantB);
        self::assertCount(3, $doProcesso);
        self::assertSame('<p>Antiga</p>', $doProcesso[0]->getConteudo());

        // O teto corta as ANTIGAS, não as novas: com limite 2 ficam as duas mais recentes, ainda
        // em ordem cronológica (empate no instante desempata pelo id).
        self::assertSame(
            ['<p>Nova</p>', '<p>Só do processo</p>'],
            array_map(static fn (NotaTecnica $n): string => $n->getConteudo(), $this->repositorio()->listarPorProcesso($processoB, $tenantB, 2)),
        );
    }

    #[TestDox('FPN-020: listarPorPublicacoesDosProcessos recorta por escritório, pelas publicações pedidas e pelos processos da pasta')]
    public function testListarPorPublicacoesDosProcessosRecorta(): void
    {
        self::bootKernel();
        $this->garantirFiltroDesligado();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $processoA         = $this->criarProcesso($tenantA, self::NUMERO);
        $outroProcessoA    = $this->criarProcesso($tenantA, '07022222222222222222'); // de outra pasta
        $processoB         = $this->criarProcesso($tenantB, self::NUMERO);
        $pub1              = $this->criarPublicacao($tenantA, '50000101', self::NUMERO, '2026-08-20', $processoA);
        $pub2              = $this->criarPublicacao($tenantA, '50000102', self::NUMERO, '2026-08-21', $processoA);
        $foraDaPagina      = $this->criarPublicacao($tenantA, '50000103', self::NUMERO, '2026-08-22', $processoA);

        $antiga = new NotaTecnica($tenantA, $processoA, $userA, '<p>Antiga da 1</p>', $pub1);
        (new \ReflectionProperty(NotaTecnica::class, 'criadaEm'))->setValue($antiga, new \DateTimeImmutable('-10 minutes'));
        $notas = [
            $antiga,
            new NotaTecnica($tenantA, $processoA, $userA, '<p>Nova da 1</p>', $pub1),
            new NotaTecnica($tenantA, $processoA, $userA, '<p>Da 2</p>', $pub2),
            new NotaTecnica($tenantA, $processoA, $userA, '<p>Só do processo</p>'),
            new NotaTecnica($tenantA, $processoA, $userA, '<p>Fora da página</p>', $foraDaPagina),
            new NotaTecnica($tenantA, $outroProcessoA, $userA, '<p>De outro processo</p>', $pub1),
            // Registro inconsistente de propósito: escritório B pendurado na publicação de A.
            new NotaTecnica($tenantB, $processoB, $userB, '<p>De B</p>', $pub1),
        ];
        foreach ($notas as $nota) {
            $this->em()->persist($nota);
        }
        $this->em()->flush();

        $conteudos = static fn (array $lista): array => array_map(static fn (NotaTecnica $n): string => $n->getConteudo(), $lista);
        $ids       = [(int) $pub1->getId(), (int) $pub2->getId()];

        self::assertSame(
            // C6-FPN-01: em ordem cronológica (empate no instante, a de id menor primeiro).
            ['<p>Antiga da 1</p>', '<p>Nova da 1</p>', '<p>Da 2</p>'],
            $conteudos($this->repositorio()->listarPorPublicacoesDosProcessos($ids, [$processoA], $tenantA)),
        );
        // O escritório B, com o processo de B, só vê a sua — e nunca as de A.
        self::assertSame(['<p>De B</p>'], $conteudos($this->repositorio()->listarPorPublicacoesDosProcessos($ids, [$processoB], $tenantB)));
        // Processo de A consultado com o escritório B: nada.
        self::assertSame([], $this->repositorio()->listarPorPublicacoesDosProcessos($ids, [$processoA], $tenantB));
        // Sem publicação ou sem processo, vazio (e não "todas").
        self::assertSame([], $this->repositorio()->listarPorPublicacoesDosProcessos([], [$processoA], $tenantA));
        self::assertSame([], $this->repositorio()->listarPorPublicacoesDosProcessos($ids, [], $tenantA));
    }
}
