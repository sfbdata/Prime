<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoAlerta;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\UseCase\MontarCentralAlertasUseCase;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Agregação tenant-wide da Central de Alertas (Etapa 9) contra o BANCO REAL. Roda em KernelTestCase
 * (TenantFilter desligado): prova o escopo por tenant pela query explícita e o reuso de `AlertasCobranca`
 * (por caso) na visão do escritório — agrupamento por carteira e resumo por tipo. Read-only.
 */
#[CoversClass(MontarCentralAlertasUseCase::class)]
final class MontarCentralAlertasUseCaseTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private MontarCentralAlertasUseCase $sut;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var ObrigacaoRepository $obrigacaoRepo */
        $obrigacaoRepo = $this->em->getRepository(Obrigacao::class);
        /** @var CasoCobrancaRepository $casoRepo */
        $casoRepo = $this->em->getRepository(CasoCobranca::class);
        /** @var AlocacaoPagamentoRepository $alocacaoRepo */
        $alocacaoRepo = $this->em->getRepository(\App\Cobranca\Entity\AlocacaoPagamento::class);
        /** @var LiquidacaoRepository $liquidacaoRepo */
        $liquidacaoRepo = $this->em->getRepository(\App\Cobranca\Entity\Liquidacao::class);
        /** @var ProximaAcaoRepository $acaoRepo */
        $acaoRepo = $this->em->getRepository(ProximaAcao::class);

        $calcSaldo = new CalculadoraSaldo($obrigacaoRepo, $casoRepo, $alocacaoRepo, $liquidacaoRepo, new \App\Cobranca\Service\EncargosVivos(new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable('2026-07-20')), new \App\Cobranca\Service\CalculadoraEncargos()), new \App\Cobranca\Service\ResolvedorConfigEncargos());
        $alertas = new AlertasCobranca($obrigacaoRepo, $acaoRepo, $calcSaldo);

        $this->sut = new MontarCentralAlertasUseCase($casoRepo, $alertas);
        $this->hoje = new \DateTimeImmutable('2026-07-20');
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Central ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function carteira(Tenant $tenant): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        return CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();
    }

    private function caso(Tenant $tenant, Carteira $carteira, StatusCaso $status = StatusCaso::Ativo): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira])->_real();

        return CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => $status,
        ])->_real();
    }

    private function obrigacao(Tenant $tenant, CasoCobranca $caso, string $vencimento): void
    {
        ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'valorOriginal' => 100000,
            'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable($vencimento),
        ]);
    }

    #[TestDox('Agrupa por carteira e resume por tipo os alertas do escritório')]
    public function testAgrupaPorCarteiraEResumo(): void
    {
        $tenant = $this->tenant();

        // Carteira A: caso com obrigação vencida → ObrigacaoVencida.
        $carteiraA = $this->carteira($tenant);
        $casoA = $this->caso($tenant, $carteiraA);
        $this->obrigacao($tenant, $casoA, '2026-06-01'); // vencida

        // Carteira B: outro caso com obrigação vencida → ObrigacaoVencida (segunda carteira com alerta).
        $carteiraB = $this->carteira($tenant);
        $casoB = $this->caso($tenant, $carteiraB);
        $this->obrigacao($tenant, $casoB, '2026-06-15'); // vencida

        $central = $this->sut->executar($tenant, null, $this->hoje);

        self::assertCount(2, $central->porCarteira);
        self::assertSame(2, $central->totalCasosComAlerta);

        $totais = [];
        foreach ($central->resumoPorTipo as $r) {
            $totais[$r['tipo']->value] = $r['total'];
        }
        self::assertSame(2, $totais[TipoAlerta::ObrigacaoVencida->value] ?? 0);
    }

    #[TestDox('Caso encerrado não aparece na central (sem alertas)')]
    public function testEncerradoSemAlerta(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $caso = $this->caso($tenant, $carteira, StatusCaso::Encerrado);
        $this->obrigacao($tenant, $caso, '2026-06-01'); // vencida, mas caso encerrado → [] alertas

        $central = $this->sut->executar($tenant, null, $this->hoje);

        self::assertSame(0, $central->totalCasosComAlerta);
        self::assertCount(0, $central->porCarteira);
    }

    #[TestDox('Escopo por tenant: alertas de outro escritório não entram')]
    public function testTenantScoping(): void
    {
        $tenantA = $this->tenant();
        $carteiraA = $this->carteira($tenantA);
        $casoA = $this->caso($tenantA, $carteiraA);
        $this->obrigacao($tenantA, $casoA, '2026-06-01');

        $tenantB = $this->tenant();
        $carteiraB = $this->carteira($tenantB);
        $casoB = $this->caso($tenantB, $carteiraB);
        $this->obrigacao($tenantB, $casoB, '2026-06-01');

        $central = $this->sut->executar($tenantA, null, $this->hoje);

        self::assertSame(1, $central->totalCasosComAlerta);
        self::assertCount(1, $central->porCarteira);
        self::assertSame($carteiraA->getId(), $central->porCarteira[0]->carteiraId);
    }

    #[TestDox('Filtro por carteira restringe a central àquela carteira')]
    public function testFiltroPorCarteira(): void
    {
        $tenant = $this->tenant();

        $carteiraX = $this->carteira($tenant);
        $casoX = $this->caso($tenant, $carteiraX);
        $this->obrigacao($tenant, $casoX, '2026-06-01');

        $carteiraY = $this->carteira($tenant);
        $casoY = $this->caso($tenant, $carteiraY);
        $this->obrigacao($tenant, $casoY, '2026-06-01');

        $central = $this->sut->executar($tenant, $carteiraX, $this->hoje);

        self::assertSame(1, $central->totalCasosComAlerta);
        self::assertCount(1, $central->porCarteira);
        self::assertSame($carteiraX->getId(), $central->carteiraId);
    }
}
