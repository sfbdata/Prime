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

    /** Caso com identificação do objeto e nome da pessoa cobrada escolhidos a dedo (busca livre). */
    private function casoNomeado(Tenant $tenant, Carteira $carteira, string $identificacao, string $nomePessoa): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => $identificacao,
        ])->_real();

        return CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant, 'nome' => $nomePessoa]),
        ])->_real();
    }

    #[TestDox('Busca livre: casa por trecho da IDENTIFICAÇÃO do objeto')]
    public function testBuscaPorIdentificacaoDoObjeto(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $alvo = $this->casoNomeado($tenant, $carteira, 'CONTRATO-9911', 'Devedor Um');
        $this->casoNomeado($tenant, $carteira, 'CONTRATO-2200', 'Devedor Dois');

        $this->em->clear();

        $resultado = $this->sut->executar($carteira, '9911');

        self::assertCount(1, $resultado['casos']);
        self::assertSame($alvo->getId(), $resultado['casos'][0]->id);
    }

    #[TestDox('Busca livre: casa por trecho do NOME da pessoa cobrada')]
    public function testBuscaPorNomeDaPessoaCobrada(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $alvo = $this->casoNomeado($tenant, $carteira, 'CONTRATO-1', 'Marcos Vinicius Teixeira');
        $this->casoNomeado($tenant, $carteira, 'CONTRATO-2', 'Helena Prado');

        $this->em->clear();

        $resultado = $this->sut->executar($carteira, 'vinicius');

        self::assertCount(1, $resultado['casos']);
        self::assertSame($alvo->getId(), $resultado['casos'][0]->id);
    }

    #[TestDox('Busca livre: ignora acento nos dois sentidos (UNACCENT), como no Expediente')]
    public function testBuscaIgnoraAcento(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $comAcento = $this->casoNomeado($tenant, $carteira, 'CONTRATO-1', 'João Gonçalves');
        $semAcento = $this->casoNomeado($tenant, $carteira, 'CONTRATO-2', 'Ana Luiza Sao Paulo');

        $this->em->clear();

        // Termo sem acento acha o nome COM acento…
        $semTermo = $this->sut->executar($carteira, 'joao goncalves');
        self::assertCount(1, $semTermo['casos']);
        self::assertSame($comAcento->getId(), $semTermo['casos'][0]->id);

        // …e o termo COM acento acha o nome gravado sem ele.
        $comTermo = $this->sut->executar($carteira, 'são paulo');
        self::assertCount(1, $comTermo['casos']);
        self::assertSame($semAcento->getId(), $comTermo['casos'][0]->id);
    }

    #[TestDox('Busca livre: filtra SÓ a lista — o cabeçalho segue somando a carteira inteira')]
    public function testBuscaNaoMexeNoCabecalho(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);

        $alvo = $this->casoNomeado($tenant, $carteira, 'CONTRATO-ALVO', 'Devedor Alvo');
        $this->obrigacao($tenant, $alvo, 100000, '2026-06-01');

        $outro = $this->casoNomeado($tenant, $carteira, 'CONTRATO-OUTRO', 'Devedor Outro');
        $this->obrigacao($tenant, $outro, 250000, '2026-06-01');

        $this->em->clear();

        $semFiltro = $this->sut->executar($carteira);
        $comFiltro = $this->sut->executar($carteira, 'ALVO');

        self::assertCount(2, $semFiltro['casos']);
        self::assertCount(1, $comFiltro['casos'], 'a lista respeita a busca');
        self::assertSame($alvo->getId(), $comFiltro['casos'][0]->id);

        self::assertSame(
            $semFiltro['carteira']->saldoConsolidado,
            $comFiltro['carteira']->saldoConsolidado,
            'buscar não muda o quanto a carteira tem a receber',
        );
        self::assertSame($semFiltro['carteira']->totalObjetos, $comFiltro['carteira']->totalObjetos);
        self::assertSame($semFiltro['carteira']->totalCasos, $comFiltro['carteira']->totalCasos);
    }

    #[TestDox('Busca livre: termo que não casa com nada devolve lista vazia (e cabeçalho intacto)')]
    public function testBuscaSemResultado(): void
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);
        $caso = $this->casoNomeado($tenant, $carteira, 'CONTRATO-1', 'Devedor Um');
        $this->obrigacao($tenant, $caso, 100000, '2026-06-01');

        $this->em->clear();

        $resultado = $this->sut->executar($carteira, 'zzzz-nao-existe');

        self::assertSame([], $resultado['casos']);
        self::assertSame(100000, $resultado['carteira']->saldoConsolidado);
    }

    #[TestDox('Busca livre: não atravessa tenant — termo que casa em B não traz nada em A')]
    public function testBuscaNaoVazaEntreTenants(): void
    {
        $tenantA = $this->tenant();
        $carteiraA = $this->carteira($tenantA);
        $this->casoNomeado($tenantA, $carteiraA, 'CONTRATO-A', 'Devedor de A');

        $tenantB = $this->tenant();
        $carteiraB = $this->carteira($tenantB);
        $this->casoNomeado($tenantB, $carteiraB, 'CONTRATO-SEGREDO-B', 'Devedor de B');

        $this->em->clear();

        $resultado = $this->sut->executar($carteiraA, 'SEGREDO-B');

        self::assertSame([], $resultado['casos'], 'busca de um escritório não alcança o outro');
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

    /**
     * Carteira com três casos de saldos conhecidos e distintos: dois VENCIDOS (relógio do setUp em
     * 2026-07-20) e um a vencer. Serve às provas de agregado, ordenação e paginação abaixo.
     *
     * @return array{Tenant, Carteira}
     */
    private function carteiraComTresCasos(): array
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);

        $c1 = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $c1, 30000, '2026-06-01');  // vencida

        $c2 = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $c2, 10000, '2026-06-15');  // vencida

        $c3 = $this->caso($tenant, $carteira);
        $this->obrigacao($tenant, $c3, 20000, '2026-12-01');  // a vencer

        $this->em->clear();

        return [$tenant, $carteira];
    }

    #[TestDox('Vencido e "com atraso" somam a carteira INTEIRA, nao a pagina exibida')]
    public function testAgregadosDeVencidoIgnoramAPaginacao(): void
    {
        [, $carteira] = $this->carteiraComTresCasos();

        // Uma linha por página: se o agregado fosse calculado sobre a fatia, ele mudaria a cada
        // virada de página — e a carteira passaria a "dever" valores diferentes conforme onde o
        // usuário está. É o erro que este teste existe para impedir.
        $pagina1 = $this->sut->executar($carteira, '', 1, 1);
        $pagina3 = $this->sut->executar($carteira, '', 3, 1);

        self::assertSame(40000, $pagina1['carteira']->saldoVencido, 'Vencido = 30000 + 10000; o de dezembro nao entra');
        self::assertSame(2, $pagina1['carteira']->totalComAtraso);
        self::assertSame(60000, $pagina1['carteira']->saldoConsolidado);

        self::assertSame($pagina1['carteira']->saldoVencido, $pagina3['carteira']->saldoVencido, 'Virar de pagina mudou o vencido da carteira');
        self::assertSame($pagina1['carteira']->totalComAtraso, $pagina3['carteira']->totalComAtraso);
        self::assertSame($pagina1['carteira']->saldoConsolidado, $pagina3['carteira']->saldoConsolidado);
    }

    #[TestDox('Buscar filtra a lista mas nao mexe no vencido da carteira')]
    public function testBuscaNaoMexeNosAgregados(): void
    {
        [, $carteira] = $this->carteiraComTresCasos();

        $semBusca = $this->sut->executar($carteira);
        $comBusca = $this->sut->executar($carteira, 'zzz-nao-casa-com-nada');

        self::assertSame(3, $semBusca['total']);
        self::assertSame(0, $comBusca['total'], 'A busca impossivel tinha de esvaziar a lista');
        self::assertSame(
            $semBusca['carteira']->saldoVencido,
            $comBusca['carteira']->saldoVencido,
            'Buscar nao pode mudar o quanto a carteira tem vencido',
        );
        self::assertSame($semBusca['carteira']->totalComAtraso, $comBusca['carteira']->totalComAtraso);
    }

    #[TestDox('Por padrao a lista vem do maior saldo para o menor')]
    public function testOrdenacaoPadraoEMaiorSaldoPrimeiro(): void
    {
        [, $carteira] = $this->carteiraComTresCasos();

        $saldos = array_map(
            static fn ($caso): int => $caso->saldoExigivel,
            $this->sut->executar($carteira)['casos'],
        );

        self::assertSame([30000, 20000, 10000], $saldos);
    }

    #[TestDox('Ordenar ascendente inverte a lista')]
    public function testOrdenacaoAscendente(): void
    {
        [, $carteira] = $this->carteiraComTresCasos();

        $saldos = array_map(
            static fn ($caso): int => $caso->saldoExigivel,
            $this->sut->executar($carteira, '', 1, 25, 'saldo', 'asc')['casos'],
        );

        self::assertSame([10000, 20000, 30000], $saldos);
    }

    #[TestDox('Paginar nao repete nem perde caso entre as paginas')]
    public function testPaginasNaoSeSobrepoem(): void
    {
        [, $carteira] = $this->carteiraComTresCasos();

        $ids = [];
        foreach ([1, 2, 3] as $pagina) {
            $resultado = $this->sut->executar($carteira, '', $pagina, 1);
            self::assertCount(1, $resultado['casos'], "A pagina {$pagina} devia trazer exatamente 1 caso");
            self::assertSame(3, $resultado['total']);
            self::assertSame(3, $resultado['total_paginas']);
            $ids[] = $resultado['casos'][0]->id;
        }

        self::assertCount(3, array_unique($ids), 'Um caso apareceu em duas paginas — e outro sumiu');
    }

    #[TestDox('Pedir pagina que nao existe cai na ultima, nao numa lista vazia')]
    public function testPaginaForaDaFaixaEGrampeada(): void
    {
        [, $carteira] = $this->carteiraComTresCasos();

        $resultado = $this->sut->executar($carteira, '', 99, 2);

        self::assertSame(2, $resultado['pagina'], 'Devia ter caido na ultima pagina existente');
        self::assertSame(2, $resultado['total_paginas']);
        self::assertCount(1, $resultado['casos'], 'A ultima pagina de 3 itens com 2 por pagina tem 1 item');
        self::assertSame(2, $resultado['por_pagina'], 'por_pagina alimenta o "Mostrando X-Y de Z" da tela');
    }

    /**
     * Carteira com os três estados representados e atraso atravessando dois deles — é este cruzamento
     * que separa "filtrar por estado" de "filtrar por atraso".
     *
     * @return array{Tenant, Carteira}
     */
    private function carteiraComEstadosVariados(): array
    {
        $tenant = $this->tenant();
        $carteira = $this->carteira($tenant);

        $ativoVencido = $this->caso($tenant, $carteira, StatusCaso::Ativo);
        $this->obrigacao($tenant, $ativoVencido, 30000, '2026-06-01');       // vencida

        $judVencido = $this->caso($tenant, $carteira, StatusCaso::Judicializado);
        $this->obrigacao($tenant, $judVencido, 10000, '2026-06-15');         // vencida

        $ativoEmDia = $this->caso($tenant, $carteira, StatusCaso::Ativo);
        $this->obrigacao($tenant, $ativoEmDia, 20000, '2026-12-01');         // a vencer

        $this->em->clear();

        return [$tenant, $carteira];
    }

    #[TestDox('Filtro de Estado recorta a lista pelo status do caso')]
    public function testFiltroDeEstadoRecortaPeloStatus(): void
    {
        [, $carteira] = $this->carteiraComEstadosVariados();

        $ativos = $this->sut->executar($carteira, '', 1, 25, 'saldo', 'desc', 'ativo');
        $judicializados = $this->sut->executar($carteira, '', 1, 25, 'saldo', 'desc', 'judicializado');
        $encerrados = $this->sut->executar($carteira, '', 1, 25, 'saldo', 'desc', 'encerrado');

        self::assertSame(2, $ativos['total'], 'Dois casos ativos: o vencido e o em dia');
        self::assertSame(['ativo', 'ativo'], array_map(static fn ($c): string => $c->statusValue, $ativos['casos']));

        self::assertSame(1, $judicializados['total']);
        self::assertSame('judicializado', $judicializados['casos'][0]->statusValue);

        self::assertSame(0, $encerrados['total'], 'A carteira nao tem caso encerrado');
        self::assertSame([], $encerrados['casos']);
    }

    #[TestDox('"So com atraso" atravessa os estados — nao e um quarto estado')]
    public function testFiltroDeVencidosAtravessaOsEstados(): void
    {
        [, $carteira] = $this->carteiraComEstadosVariados();

        $resultado = $this->sut->executar($carteira, '', 1, 25, 'saldo', 'desc', 'vencidos');

        self::assertSame(2, $resultado['total'], 'Um ativo e um judicializado estao vencidos');
        self::assertSame(
            ['ativo', 'judicializado'],
            array_map(static fn ($c): string => $c->statusValue, $resultado['casos']),
            'A ordem e por saldo desc (30000 ativo, 10000 judicializado); o que importa e que os DOIS estados vieram',
        );
        foreach ($resultado['casos'] as $caso) {
            self::assertTrue($caso->temVencido, 'Entrou na lista de "so com atraso" um caso sem atraso');
        }
    }

    #[TestDox('Filtrar por Estado NAO mexe nos agregados do cabecalho')]
    public function testFiltroDeEstadoNaoMexeNosAgregados(): void
    {
        [, $carteira] = $this->carteiraComEstadosVariados();

        $semFiltro = $this->sut->executar($carteira);
        $soJudicializados = $this->sut->executar($carteira, '', 1, 25, 'saldo', 'desc', 'judicializado');

        self::assertSame(3, $semFiltro['total']);
        self::assertSame(1, $soJudicializados['total'], 'O filtro tinha de recortar a LISTA');

        // O cabecalho responde "quanto esta carteira tem a receber". Escolher um recorte na lista nao
        // pode mudar essa resposta — e o defeito seria invisivel: um numero menor, com cara de certo.
        self::assertSame(60000, $semFiltro['carteira']->saldoConsolidado);
        self::assertSame($semFiltro['carteira']->saldoConsolidado, $soJudicializados['carteira']->saldoConsolidado);
        self::assertSame($semFiltro['carteira']->saldoVencido, $soJudicializados['carteira']->saldoVencido);
        self::assertSame($semFiltro['carteira']->totalComAtraso, $soJudicializados['carteira']->totalComAtraso);
        self::assertSame($semFiltro['carteira']->totalCasos, $soJudicializados['carteira']->totalCasos);
    }

    #[TestDox('Estado desconhecido na URL e ignorado — a lista volta inteira')]
    public function testEstadoDesconhecidoNaoFiltra(): void
    {
        [, $carteira] = $this->carteiraComEstadosVariados();

        $resultado = $this->sut->executar($carteira, '', 1, 25, 'saldo', 'desc', 'pronto-para-encerrar');

        self::assertSame(3, $resultado['total'], 'Valor fora da lista branca nao pode esvaziar a tela');
    }
}
