<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\UseCase\MontarVisaoCarteiraUseCase;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\AcordoDocumentoFactory;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\CobrancaDocumentoFactory;
use App\Tests\Factory\Cobranca\LiquidacaoFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Visão da Carteira (Etapa 8, agora batch — P2 da otimização de queries) contra o BANCO REAL. Prova que a
 * agregação em LOTE (`CalculadoraSaldo::saldosDosCasos`) devolve o MESMO `saldoConsolidado` e os MESMOS
 * saldos por caso que a soma dos métodos por-caso `saldoExigivel`/`saldoVencido` — só troca a fonte dos
 * dados. Escopo por tenant da carteira; casos encerrados entram na lista com saldo derivado (≈0).
 */
#[CoversClass(MontarVisaoCarteiraUseCase::class)]
#[CoversClass(CasoCobrancaRepository::class)]
final class MontarVisaoCarteiraUseCaseTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private MontarVisaoCarteiraUseCase $sut;
    private CalculadoraSaldo $calcSaldo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var ObrigacaoRepository $obrigacaoRepo */
        $obrigacaoRepo = $this->em->getRepository(Obrigacao::class);
        /** @var CasoCobrancaRepository $casoRepo */
        $casoRepo = $this->em->getRepository(CasoCobranca::class);
        /** @var AlocacaoPagamentoRepository $alocacaoRepo */
        $alocacaoRepo = $this->em->getRepository(AlocacaoPagamento::class);
        /** @var LiquidacaoRepository $liquidacaoRepo */
        $liquidacaoRepo = $this->em->getRepository(Liquidacao::class);
        /** @var ObjetoCobrancaRepository $objetoRepo */
        $objetoRepo = $this->em->getRepository(\App\Cobranca\Entity\ObjetoCobranca::class);

        $this->calcSaldo = new CalculadoraSaldo($obrigacaoRepo, $casoRepo, $alocacaoRepo, $liquidacaoRepo, new \App\Cobranca\Service\EncargosVivos(new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable('2026-07-20')), new \App\Cobranca\Service\CalculadoraEncargos(), new \App\Cobranca\Service\ResolvedorConfigEncargos()), new \App\Cobranca\Service\ResolvedorConfigEncargos());
        $this->sut = new MontarVisaoCarteiraUseCase($objetoRepo, $casoRepo, $this->calcSaldo, $this->em->getConnection());
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant VC ' . uniqid());
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

    private function obrigacao(Tenant $tenant, CasoCobranca $caso, int $valor, string $vencimento): Obrigacao
    {
        return ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'valorOriginal' => $valor, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable($vencimento),
        ])->_real();
    }

    private function pagamentoAlocado(Tenant $tenant, CasoCobranca $caso, Obrigacao $obrigacao, int $valor): void
    {
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'valorDivida' => $valor, 'valorEncargos' => 0, 'valorHonorarios' => 0,
            'data' => new \DateTimeImmutable('2026-07-10'),
        ])->_real();

        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant, 'pagamento' => $pagamento, 'obrigacao' => $obrigacao, 'valor' => $valor,
        ]);
    }

    #[TestDox('saldoConsolidado e os saldos por caso batem com a soma dos serviços por-caso')]
    public function testConsolidadoEPorCasoBatemComPorCaso(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);

        // Caso ativo com pagamento alocado + liquidação (exercita alocado/liquidado no lote).
        $c1 = $this->caso($tenant, $carteira);
        $o1 = $this->obrigacao($tenant, $c1, 100000, '2026-06-01'); // vencida
        $this->pagamentoAlocado($tenant, $c1, $o1, 20000);
        LiquidacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $c1,
            'valorReconhecido' => 10000, 'data' => new \DateTimeImmutable('2026-07-10'),
        ]);

        // Caso a vencer (sem vencido).
        $c2 = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $c2, 50000, '2026-12-01');

        // Caso encerrado (entra na lista; saldo derivado ≈ 0 após pagamento total).
        $c3 = $this->caso($tenant, $carteira, StatusCaso::Encerrado);
        $o3 = $this->obrigacao($tenant, $c3, 40000, '2026-06-01');
        $this->pagamentoAlocado($tenant, $c3, $o3, 40000);

        $this->em->clear();

        $resultado = $this->sut->executar($carteira);
        $casosOutput = $resultado['casos'];
        $detalhe = $resultado['carteira'];

        self::assertCount(3, $casosOutput);

        // Fonte de verdade por-caso (recarregada limpa).
        $casosDoBanco = $this->em->getRepository(CasoCobranca::class)->daCarteira($carteira);
        $consolidadoEsperado = 0;
        $exigivelPorId = [];
        $vencidoPorId = [];
        foreach ($casosDoBanco as $caso) {
            $id = $caso->getId();
            self::assertNotNull($id);
            $exigivelPorId[$id] = $this->calcSaldo->saldoExigivel($caso);
            $vencidoPorId[$id] = $this->calcSaldo->saldoVencido($caso);
            $consolidadoEsperado += $exigivelPorId[$id];
        }

        self::assertSame($consolidadoEsperado, $detalhe->saldoConsolidado);
        foreach ($casosOutput as $out) {
            self::assertSame($exigivelPorId[$out->id], $out->saldoExigivel, sprintf('exigivel caso %d', $out->id));
            self::assertSame($vencidoPorId[$out->id], $out->saldoVencido, sprintf('vencido caso %d', $out->id));
        }
    }

    #[TestDox('Escopo por tenant: a visão só agrega casos da carteira daquele escritório')]
    public function testEscopoPorTenant(): void
    {
        $tenantA = $this->tenant();
        $carteiraA = $this->carteira($tenantA);
        $cA = $this->caso($tenantA, $carteiraA);
        $this->obrigacao($tenantA, $cA, 100000, '2026-06-01');

        $tenantB = $this->tenant();
        $carteiraB = $this->carteira($tenantB);
        $cB = $this->caso($tenantB, $carteiraB);
        $this->obrigacao($tenantB, $cB, 500000, '2026-06-01');

        $this->em->clear();

        $resultado = $this->sut->executar($carteiraA);

        self::assertCount(1, $resultado['casos']);
        self::assertSame(100000, $resultado['carteira']->saldoConsolidado);
    }

    #[TestDox('Grampo (#6): acende quando o objeto tem documento em algum CASO')]
    public function testGrampoAcendeComDocumentoNoCaso(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $caso = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $caso, 1000, '2026-06-01');

        CobrancaDocumentoFactory::createOne(['tenant' => $tenant, 'caso' => $caso]);

        $this->em->clear();

        $resultado = $this->sut->executar($carteira);

        self::assertCount(1, $resultado['casos']);
        self::assertTrue($resultado['casos'][0]->temDocumentos);
    }

    #[TestDox('Grampo (#6): acende quando o objeto tem documento só num ACORDO')]
    public function testGrampoAcendeComDocumentoSoNoAcordo(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $caso = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $caso, 1000, '2026-06-01');

        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso])->_real();
        AcordoDocumentoFactory::createOne(['tenant' => $tenant, 'acordo' => $acordo]);

        $this->em->clear();

        $resultado = $this->sut->executar($carteira);

        self::assertCount(1, $resultado['casos']);
        self::assertTrue($resultado['casos'][0]->temDocumentos);
    }

    #[TestDox('Grampo (#6): apagado quando o objeto não tem documento algum')]
    public function testGrampoApagadoSemDocumentos(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $caso = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $caso, 1000, '2026-06-01');

        $this->em->clear();

        $resultado = $this->sut->executar($carteira);

        self::assertCount(1, $resultado['casos']);
        self::assertFalse($resultado['casos'][0]->temDocumentos);
    }

    #[TestDox('Grampo (#6): NÃO conta documento de objeto de OUTRO tenant')]
    public function testGrampoNaoContaDocumentoDeOutroTenant(): void
    {
        $tenantA = $this->tenant();
        $carteiraA = $this->carteira($tenantA);
        $casoA = $this->caso($tenantA, $carteiraA);
        $this->obrigacao($tenantA, $casoA, 1000, '2026-06-01');

        // Documento de OUTRO tenant, sem relação alguma com o caso/objeto de A.
        $tenantB = $this->tenant();
        CobrancaDocumentoFactory::createOne(['tenant' => $tenantB]);

        $this->em->clear();

        $resultado = $this->sut->executar($carteiraA);

        self::assertCount(1, $resultado['casos']);
        self::assertFalse($resultado['casos'][0]->temDocumentos);
    }
}
