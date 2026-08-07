<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoAlerta;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use Symfony\Component\Clock\MockClock;
use App\Cobranca\UseCase\MontarDashboardCobrancaUseCase;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\AlocacaoPagamentoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\LiquidacaoFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PagamentoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cobranca\ProximaAcaoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Agregação tenant-wide do Dashboard (Etapa 9) contra o BANCO REAL. Roda em KernelTestCase (TenantFilter
 * desligado, como no CLI): prova que a agregação é tenant-scoped pela query explícita (`doTenant`) e que
 * os números derivam dos serviços de saldo/honorários/alertas — nada de saldo materializado. Cenários com
 * valores fechados em centavos para asserção exata (invariável 20, §18/§19/§20).
 */
#[CoversClass(MontarDashboardCobrancaUseCase::class)]
#[CoversClass(CasoCobrancaRepository::class)]
final class MontarDashboardCobrancaUseCaseTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private MontarDashboardCobrancaUseCase $sut;

    private \DateTimeImmutable $inicio;
    private \DateTimeImmutable $fim;
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
        $alocacaoRepo = $this->em->getRepository(AlocacaoPagamento::class);
        /** @var LiquidacaoRepository $liquidacaoRepo */
        $liquidacaoRepo = $this->em->getRepository(Liquidacao::class);
        $pagamentoRepo = $this->em->getRepository(\App\Cobranca\Entity\Pagamento::class);
        /** @var ProximaAcaoRepository $acaoRepo */
        $acaoRepo = $this->em->getRepository(\App\Cobranca\Entity\ProximaAcao::class);

        $calcSaldo = new CalculadoraSaldo(
            $obrigacaoRepo,
            $casoRepo,
            $alocacaoRepo,
            $liquidacaoRepo,
            new EncargosVivos(new MockClock(new \DateTimeImmutable('2026-07-20')), new CalculadoraEncargos(), new ResolvedorConfigEncargos()),
            new ResolvedorConfigEncargos(),
        );
        $calcHon = new CalculadoraHonorarios(new ResolvedorConfigEncargos());

        $this->sut = new MontarDashboardCobrancaUseCase(
            $casoRepo,
            $obrigacaoRepo,
            $alocacaoRepo,
            $pagamentoRepo,
            $liquidacaoRepo,
            $acaoRepo,
            $calcSaldo,
            $calcHon,
            new EncargosVivos(new MockClock(new \DateTimeImmutable('2026-07-20')), new CalculadoraEncargos(), new ResolvedorConfigEncargos()),
            new ResolvedorConfigEncargos(),
            // O painel não monta alertas: usa só o predicado `vencidaEmAberto`, a régua única do que
            // conta como vencido em aberto (antes ele repetia esse critério à mão).
            new AlertasCobranca($obrigacaoRepo, $acaoRepo, $calcSaldo, $alocacaoRepo),
        );

        $this->inicio = new \DateTimeImmutable('2026-07-01 00:00:00');
        $this->fim = new \DateTimeImmutable('2026-07-31 23:59:59');
        $this->hoje = new \DateTimeImmutable('2026-07-20');
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Dash ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function carteira(Tenant $tenant, FormaHonorarios $forma = FormaHonorarios::SemPercentual, ?string $percentual = null, ModoCarteira $modo = ModoCarteira::Unico): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        return CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => $cliente,
            'modo' => $modo,
            'formaHonorarios' => $forma,
            'percentualHonorarios' => $percentual,
        ])->_real();
    }

    private function caso(Tenant $tenant, Carteira $carteira, StatusCaso $status = StatusCaso::Ativo, ?\App\Cobranca\Entity\ObjetoCobranca $objeto = null): CasoCobranca
    {
        $objeto ??= ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira])->_real();

        return CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'status' => $status,
            'formaHonorarios' => $carteira->getFormaHonorarios(),
            'percentualHonorarios' => $carteira->getPercentualHonorarios(),
        ])->_real();
    }

    private function obrigacao(Tenant $tenant, CasoCobranca $caso, int $valor, string $vencimento): Obrigacao
    {
        return ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'valorOriginal' => $valor,
            'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable($vencimento),
        ])->_real();
    }

    /** Cria pagamento (com alocação à obrigação) que abate o saldo. */
    private function pagamento(Tenant $tenant, CasoCobranca $caso, Obrigacao $obrigacao, int $valorDivida, int $valorHonorarios, string $data): void
    {
        $pagamento = PagamentoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'valorDivida' => $valorDivida,
            'valorEncargos' => 0,
            'valorHonorarios' => $valorHonorarios,
            'data' => new \DateTimeImmutable($data),
        ])->_real();

        AlocacaoPagamentoFactory::createOne([
            'tenant' => $tenant,
            'pagamento' => $pagamento,
            'obrigacao' => $obrigacao,
            'valor' => $valorDivida,
        ]);
    }

    #[TestDox('Financeira + resultado: saldo em aberto/vencido, recuperado no período e taxa')]
    public function testFinanceiroEResultadoBasico(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $caso = $this->caso($tenant, $carteira);
        $obrigacao = $this->obrigacao($tenant, $caso, 100000, '2026-06-01'); // vencida
        $this->pagamento($tenant, $caso, $obrigacao, 30000, 0, '2026-07-10'); // dentro do período

        $d = $this->sut->executar($tenant, null, $this->inicio, $this->fim, $this->hoje);

        self::assertSame(70000, $d->saldoEmAberto);
        self::assertSame(70000, $d->saldoVencido);
        self::assertSame(30000, $d->valorRecuperadoNoPeriodo);
        self::assertSame(0, $d->honorariosProjetados);
        self::assertSame(0, $d->honorariosRealizadosNoPeriodo);
        self::assertSame(30000, $d->valorTotalRecuperado);
        self::assertSame(70000, $d->valorEmAberto);
        self::assertSame(3000, $d->taxaRecuperacaoBasisPoints); // 30000 / 100000 = 30%
        self::assertSame(1, $d->objetosInadimplentes);
        self::assertSame(1, $d->casosAtivos);
        self::assertSame(1, $d->pagamentosAVerificar); // obrigação vencida a verificar
        self::assertSame(1, $d->totalCasos);
    }

    #[TestDox('Recuperado fora do período não conta no período, mas conta no total')]
    public function testRecuperadoForaDoPeriodo(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $caso = $this->caso($tenant, $carteira);
        $obrigacao = $this->obrigacao($tenant, $caso, 100000, '2026-06-01');
        $this->pagamento($tenant, $caso, $obrigacao, 20000, 0, '2026-06-15'); // FORA do período
        $this->pagamento($tenant, $caso, $obrigacao, 10000, 0, '2026-07-15'); // dentro

        $d = $this->sut->executar($tenant, null, $this->inicio, $this->fim, $this->hoje);

        self::assertSame(10000, $d->valorRecuperadoNoPeriodo);
        self::assertSame(30000, $d->valorTotalRecuperado);
        self::assertSame(70000, $d->saldoEmAberto);
    }

    #[TestDox('Honorários acrescidos: realizados = valor separado no pagamento; projetados sobre o aberto')]
    public function testHonorariosAcrescido(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant, FormaHonorarios::AcrescidoDivida, '10.00');
        $caso = $this->caso($tenant, $carteira);
        $obrigacao = $this->obrigacao($tenant, $caso, 200000, '2026-06-01');
        $this->pagamento($tenant, $caso, $obrigacao, 90000, 10000, '2026-07-10');

        $d = $this->sut->executar($tenant, null, $this->inicio, $this->fim, $this->hoje);

        self::assertSame(10000, $d->honorariosRealizadosNoPeriodo); // valorHonorarios do pagamento
        self::assertSame(110000, $d->saldoEmAberto); // 200000 - 90000
        self::assertSame(11000, $d->honorariosProjetados); // 110000 * 10%
    }

    #[TestDox('Honorários retidos: realizados/projetados derivados do percentual sobre a recuperação')]
    public function testHonorariosRetido(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant, FormaHonorarios::RetidoRecuperado, '20.00');
        $caso = $this->caso($tenant, $carteira);
        $obrigacao = $this->obrigacao($tenant, $caso, 100000, '2026-06-01');
        $this->pagamento($tenant, $caso, $obrigacao, 50000, 0, '2026-07-10');

        $d = $this->sut->executar($tenant, null, $this->inicio, $this->fim, $this->hoje);

        self::assertSame(10000, $d->honorariosRealizadosNoPeriodo); // 50000 recuperado * 20%
        self::assertSame(10000, $d->honorariosProjetados); // (100000-50000) * 20%
    }

    #[TestDox('Liquidação conta como recuperação, mas não gera honorários realizados')]
    public function testLiquidacaoRecuperaSemHonorarios(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant, FormaHonorarios::RetidoRecuperado, '20.00');
        $caso = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $caso, 100000, '2026-06-01');
        LiquidacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'valorReconhecido' => 40000,
            'data' => new \DateTimeImmutable('2026-07-10'),
        ]);

        $d = $this->sut->executar($tenant, null, $this->inicio, $this->fim, $this->hoje);

        self::assertSame(40000, $d->valorRecuperadoNoPeriodo);
        self::assertSame(40000, $d->valorTotalRecuperado);
        self::assertSame(60000, $d->saldoEmAberto); // 100000 - 40000
        self::assertSame(0, $d->honorariosRealizadosNoPeriodo);
    }

    #[TestDox('Caso encerrado fica fora do aberto/operacional, mas entra no recuperado acumulado')]
    public function testEncerradoForaDoAbertoDentroDoRecuperado(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $caso = $this->caso($tenant, $carteira, StatusCaso::Encerrado);
        $obrigacao = $this->obrigacao($tenant, $caso, 100000, '2026-06-01');
        $this->pagamento($tenant, $caso, $obrigacao, 100000, 0, '2026-07-10');

        $d = $this->sut->executar($tenant, null, $this->inicio, $this->fim, $this->hoje);

        self::assertSame(0, $d->saldoEmAberto);
        self::assertSame(0, $d->casosAtivos);
        self::assertSame(0, $d->objetosInadimplentes);
        self::assertSame(0, $d->pagamentosAVerificar);
        self::assertSame(100000, $d->valorTotalRecuperado);
        self::assertSame(100000, $d->valorRecuperadoNoPeriodo);
    }

    #[TestDox('Agregação é tenant-scoped: casos de outro escritório não entram')]
    public function testTenantScoping(): void
    {
        $tenantA = $this->tenant();
        $carteiraA = $this->carteira($tenantA);
        $casoA = $this->caso($tenantA, $carteiraA);
        $this->obrigacao($tenantA, $casoA, 100000, '2026-06-01');

        $tenantB = $this->tenant();
        $carteiraB = $this->carteira($tenantB);
        $casoB = $this->caso($tenantB, $carteiraB);
        $this->obrigacao($tenantB, $casoB, 500000, '2026-06-01');

        $d = $this->sut->executar($tenantA, null, $this->inicio, $this->fim, $this->hoje);

        self::assertSame(100000, $d->saldoEmAberto);
        self::assertSame(1, $d->totalCasos);
    }

    #[TestDox('Filtro por carteira restringe a agregação àquela carteira')]
    public function testFiltroPorCarteira(): void
    {
        $tenant = $this->tenant();
        $carteiraX = $this->carteira($tenant);
        $casoX = $this->caso($tenant, $carteiraX);
        $this->obrigacao($tenant, $casoX, 100000, '2026-06-01');

        $carteiraY = $this->carteira($tenant);
        $casoY = $this->caso($tenant, $carteiraY);
        $this->obrigacao($tenant, $casoY, 300000, '2026-06-01');

        $d = $this->sut->executar($tenant, $carteiraX, $this->inicio, $this->fim, $this->hoje);

        self::assertSame(100000, $d->saldoEmAberto);
        self::assertSame(1, $d->totalCasos);
        self::assertSame($carteiraX->getId(), $d->carteiraId);
    }

    #[TestDox('Objetos inadimplentes distintos vs casos ativos (modo múltiplo)')]
    public function testObjetosInadimplentesVsCasosAtivos(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant, modo: ModoCarteira::Multiplo);
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira])->_real();

        $caso1 = $this->caso($tenant, $carteira, objeto: $objeto);
        $this->obrigacao($tenant, $caso1, 100000, '2026-06-01');
        $caso2 = $this->caso($tenant, $carteira, objeto: $objeto);
        $this->obrigacao($tenant, $caso2, 50000, '2026-06-01');

        $d = $this->sut->executar($tenant, null, $this->inicio, $this->fim, $this->hoje);

        self::assertSame(1, $d->objetosInadimplentes); // mesmo objeto
        self::assertSame(2, $d->casosAtivos);
    }

    #[TestDox('Tenant sem casos: tudo zero e taxa 0 (empty state)')]
    public function testTenantSemCasos(): void
    {
        $tenant = $this->tenant();

        $d = $this->sut->executar($tenant, null, $this->inicio, $this->fim, $this->hoje);

        self::assertSame(0, $d->totalCasos);
        self::assertSame(0, $d->saldoEmAberto);
        self::assertSame(0, $d->valorTotalRecuperado);
        self::assertSame(0, $d->taxaRecuperacaoBasisPoints);
        self::assertSame(0, $d->objetosInadimplentes);
    }

    #[TestDox('Consistência: os agregados em LOTE batem com a soma dos serviços por-caso (saldo + alertas)')]
    public function testConsistenciaComServicosPorCaso(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant); // SemPercentual — foco em saldo/contagens

        // Cenário variado cobrindo cada condição de alerta + um encerrado (só recuperado).
        // casoVencida também carrega pagamento parcial ALOCADO + liquidação → prova a fiação do mapa
        // `alocadoPorObrigacao` e do `totalLiquidadoDoCaso` dentro do batch (não só a aritmética pura).
        $casoVencida = $this->caso($tenant, $carteira);
        $obrVencida = $this->obrigacao($tenant, $casoVencida, 100000, '2026-06-01'); // vencida, sem acordo
        $this->pagamento($tenant, $casoVencida, $obrVencida, 20000, 0, '2026-07-10'); // alocado 20000
        LiquidacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $casoVencida,
            'valorReconhecido' => 10000, 'data' => new \DateTimeImmutable('2026-07-10'),
        ]);

        $casoAVencer = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $casoAVencer, 50000, '2026-09-01'); // a vencer (ativo, saldo>0, sem alerta)

        $casoAcao = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $casoAcao, 70000, '2026-06-15'); // vencida
        ProximaAcaoFactory::createOne(['tenant' => $tenant, 'caso' => $casoAcao, 'prazo' => new \DateTimeImmutable('2026-07-01')]); // atrasada

        $casoParcela = $this->caso($tenant, $carteira);
        $acordo = AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $casoParcela, 'status' => StatusAcordo::Ativo])->_real();
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $casoParcela,
            'valorOriginal' => 30000, 'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-06-20'), 'acordoOrigem' => $acordo,
        ]); // parcela de acordo vigente, vencida

        // DISCRIMINANTE da regra "obrigação já paga não alerta" (07/08): caso ATIVO cuja única vencida
        // está 100% quitada. Sem ele o cenário não distingue a guarda ligada da desligada — o único caso
        // com vencida totalmente paga era o ENCERRADO, que os dois lados já excluem antes de contar, e o
        // assert de consistência ficava verde vazio (achado da revisão).
        $casoQuitada = $this->caso($tenant, $carteira);
        $obrQuitada = $this->obrigacao($tenant, $casoQuitada, 40000, '2026-06-01');
        $this->pagamento($tenant, $casoQuitada, $obrQuitada, 40000, 0, '2026-07-10');

        $casoEncerrado = $this->caso($tenant, $carteira, StatusCaso::Encerrado);
        $obrEnc = $this->obrigacao($tenant, $casoEncerrado, 40000, '2026-06-01');
        $this->pagamento($tenant, $casoEncerrado, $obrEnc, 40000, 0, '2026-07-10');

        $d = $this->sut->executar($tenant, null, $this->inicio, $this->fim, $this->hoje);

        // Fonte de verdade por-caso que o LOTE deve espelhar.
        $obrigacaoRepo = $this->em->getRepository(Obrigacao::class);
        $calcSaldo = new CalculadoraSaldo(
            $obrigacaoRepo,
            $this->em->getRepository(CasoCobranca::class),
            $this->em->getRepository(AlocacaoPagamento::class),
            $this->em->getRepository(Liquidacao::class),
            new EncargosVivos(new MockClock(new \DateTimeImmutable('2026-07-20')), new CalculadoraEncargos(), new ResolvedorConfigEncargos()),
            new ResolvedorConfigEncargos(),
        );
        $alertas = new AlertasCobranca(
            $obrigacaoRepo,
            $this->em->getRepository(\App\Cobranca\Entity\ProximaAcao::class),
            $calcSaldo,
            $this->em->getRepository(AlocacaoPagamento::class),
        );

        $saldoEsperado = 0;
        $contagem = [];
        foreach ([$casoVencida, $casoAVencer, $casoAcao, $casoParcela, $casoQuitada] as $c) {
            $saldoEsperado += $calcSaldo->saldoExigivel($c);
            foreach ($alertas->alertasDoCaso($c, $this->hoje) as $a) {
                $contagem[$a->tipo->value] = ($contagem[$a->tipo->value] ?? 0) + 1;
            }
        }

        self::assertSame($saldoEsperado, $d->saldoEmAberto);
        self::assertSame($contagem[TipoAlerta::ObrigacaoVencida->value] ?? 0, $d->pagamentosAVerificar);
        self::assertSame($contagem[TipoAlerta::ParcelaAcordoVencida->value] ?? 0, $d->parcelasAcordoVencidas);
        self::assertSame($contagem[TipoAlerta::AcaoAtrasada->value] ?? 0, $d->proximasAcoesAtrasadas);
        // O número ABSOLUTO, e não só o espelho: se os dois lados regredirem JUNTOS (foi o que aconteceu
        // quando o Dashboard mantinha a cópia da regra), o assert de espelho acima continua verde.
        // 3 = casoVencida (pago em parte) + casoAcao + casoParcela. casoQuitada NÃO conta.
        self::assertSame(3, $d->pagamentosAVerificar);
        // recuperado all-time = 20000 (pgto casoVencida) + 10000 (liquidação casoVencida)
        //                     + 40000 (pgto casoQuitada) + 40000 (pgto encerrado)
        self::assertSame(110000, $d->valorTotalRecuperado);
        self::assertSame(5, $d->casosAtivos);
    }
}
