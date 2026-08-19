<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AlocacaoPagamentoInput;
use App\Cobranca\DTO\RegistrarPagamentoInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\PagamentoExcedeSaldoException;
use App\Cobranca\Exception\PagamentoInconsistenteException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\AlocadorPagamento;
use App\Cobranca\Service\AutoAlocadorFifo;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use Symfony\Component\Clock\MockClock;
use App\Cobranca\Service\ReconciliadorLiquidacao;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\RegistrarPagamentoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrarPagamentoUseCase::class)]
final class RegistrarPagamentoUseCaseTest extends TestCase
{
    private PagamentoRepository&MockObject $pagamentoRepository;
    private CasoCobrancaRepository&MockObject $casoRepository;
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private RegistrarPagamentoUseCase $sut;
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->pagamentoRepository = $this->createMock(PagamentoRepository::class);
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        // AlocadorPagamento, AutoAlocadorFifo, CalculadoraHonorarios e o Reconciliador são finais/puros:
        // usa-se os REAIS. Sem carteira/config nos casos de teste → encargo 0 (exigível = valor original).
        $calculadora = new CalculadoraHonorarios(new ResolvedorConfigEncargos());
        $alocador = new AlocadorPagamento($this->obrigacaoRepository, $calculadora);
        $autoAlocador = new AutoAlocadorFifo(
            $this->obrigacaoRepository,
            $this->alocacaoRepository,
            $this->createMock(LiquidacaoRepository::class),
            $calculadora,
            new EncargosVivos(new MockClock(new \DateTimeImmutable('2026-07-20')), new CalculadoraEncargos(), new ResolvedorConfigEncargos()),
            new ResolvedorConfigEncargos(),
        );
        // RegistrarEventoHistorico é final: usa-se o REAL com o repositório de eventos mockado.
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new RegistrarPagamentoUseCase(
            $this->pagamentoRepository,
            $this->casoRepository,
            $alocador,
            $autoAlocador,
            $registrarEvento,
            $this->alocacaoRepository,
            new ResolvedorConfigEncargos(),
            new ReconciliadorLiquidacao(new CalculadoraEncargos(), new ResolvedorConfigEncargos()),
        );
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function registraPagamentoManualSemInventarSplit(): void
    {
        // Era `registraPagamentoAcrescidoDividaRateandoHonorarios`. No lançamento MANUAL o sistema não
        // reparte mais o dinheiro por categoria (spec `cobranca-honorario-no-total.md` §4.3): o split
        // existe quando a CONTABILIDADE o declara, e aí quem o grava é o importador de receitas.
        $carteira = (new Carteira())->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)->setPercentualHonorarios('10.00');
        $objeto = (new ObjetoCobranca())->setCarteira($carteira);
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setObjeto($objeto);
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);

        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(50, $this->tenant)
            ->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);

        // Pagamento persistido sem flush; o evento fecha a transação com flush: true.
        $this->pagamentoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(Pagamento::class));
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $pagamento = $this->sut->executar(
            $this->novoInput(50, 1100, [$this->alocacao(5, 1100)]),
            $this->tenant,
            $this->criadoPor,
        );

        self::assertSame(1100, $pagamento->getValorDivida());
        self::assertSame(0, $pagamento->getValorEncargos());
        self::assertSame(0, $pagamento->getValorHonorarios());
        self::assertCount(1, $pagamento->getAlocacoes());
        self::assertSame($this->tenant, $pagamento->getTenant());
        self::assertSame($caso, $pagamento->getCaso());
        self::assertSame($this->criadoPor, $pagamento->getCriadoPor());
        self::assertSame(1100, $pagamento->valorTotalRecebido(), 'o recebido continua sendo o bruto pago');
    }

    #[Test]
    public function registraPagamentoSemPercentualSemHonorarios(): void
    {
        // sem_percentual (default): honorários 0, toda a dívida vai para a obrigação.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);

        $this->pagamentoRepository->expects($this->once())->method('salvar');
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $pagamento = $this->sut->executar(
            $this->novoInput(50, 5000, [$this->alocacao(7, 5000)]),
            $this->tenant,
            $this->criadoPor,
        );

        self::assertSame(5000, $pagamento->getValorDivida());
        self::assertSame(0, $pagamento->getValorHonorarios());
        self::assertCount(1, $pagamento->getAlocacoes());
    }

    #[Test]
    public function rejeitaAlocacaoDeObrigacaoDeOutroCaso(): void
    {
        // Invariável 12: a obrigação alocada é de OUTRO caso (outra instância).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $outroCaso = (new CasoCobranca())->setTenant($this->tenant);
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($outroCaso);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->pagamentoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoDeOutroCasoException::class);

        $this->sut->executar(
            $this->novoInput(50, 5000, [$this->alocacao(9, 5000)]),
            $this->tenant,
            $this->criadoPor,
        );
    }

    #[Test]
    public function rejeitaPagamentoInconsistente(): void
    {
        // Σ das alocações (4000) diverge da parte da dívida (5000).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->pagamentoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(PagamentoInconsistenteException::class);

        $this->sut->executar(
            $this->novoInput(50, 5000, [$this->alocacao(11, 4000)]),
            $this->tenant,
            $this->criadoPor,
        );
    }

    #[Test]
    public function rejeitaPagamentoEmCasoEncerrado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setStatus(StatusCaso::Encerrado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->pagamentoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $this->sut->executar(
            $this->novoInput(50, 5000, [$this->alocacao(7, 5000)]),
            $this->tenant,
            $this->criadoPor,
        );
    }

    #[Test]
    public function rejeitaCasoDeOutroTenant(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->pagamentoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $this->sut->executar(
            $this->novoInput(999, 5000, [$this->alocacao(7, 5000)]),
            $this->tenant,
            $this->criadoPor,
        );
    }

    #[Test]
    public function autoAlocaPorFifoQuandoNaoManual(): void
    {
        // Sem alocações no input (modo auto = padrão): o FIFO gera a alocação sobre a obrigação exigível.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, 50);
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)->setCaso($caso)
            ->setDescricao('Parcela')->setValorOriginal(10000)
            ->setVencimentoOriginal(new \DateTimeImmutable('-1 day'));
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($obrigacao, 7);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$obrigacao]);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);

        $this->pagamentoRepository->expects($this->once())->method('salvar');
        $this->eventoRepository->expects($this->once())->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new RegistrarPagamentoInput();
        $input->casoId = 50;
        $input->data = new \DateTimeImmutable('2026-04-15');
        $input->valorPago = 5000; // < exigível → FIFO aloca 5000 na obrigação, sem exceder.

        $pagamento = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame(5000, $pagamento->getValorDivida());
        self::assertCount(1, $pagamento->getAlocacoes());
        self::assertSame(5000, $pagamento->getAlocacoes()->first()->getValor());
        self::assertSame($obrigacao, $pagamento->getAlocacoes()->first()->getObrigacao());
    }

    #[Test]
    #[TestDox('Ponta-a-ponta: taxa PRÓPRIA da obrigação (maior que a do caso) impede a liquidação prematura ao registrar o pagamento')]
    public function registrarPagamentoRespeitaTaxaPropriaDaObrigacaoNaLiquidacao(): void
    {
        // Caso com taxa de juros 1% a.m. (base, T1: override mora no OBJETO — o caso deixou de
        // participar da cascata); a obrigação tem OVERRIDE de 3% a.m. — o exigível-OVERLAY (105,00) é
        // maior que o exigível-BASE do caso (103,00). Vencimento 30 dias antes da data do pagamento.
        // Pagando exatamente o exigível-BASE (103,00), a obrigação NÃO pode liquidar (a taxa que rege
        // ela é a própria, mais alta) — prova o caminho completo (Controller-less) Registrar →
        // Reconciliador, não só o Reconciliador isolado.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $objeto = (new ObjetoCobranca())->setTaxaJurosMensalBp(100)->setTaxaMultaBp(200);
        $caso->setObjeto($objeto);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, 60);
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)->setCaso($caso)
            ->setDescricao('Parcela')->setValorOriginal(10000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-06-20'))
            ->setTaxaJurosMensalBp(300); // override: 3% a.m., acima do 1% do caso.
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($obrigacao, 9);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->pagamentoRepository->expects($this->once())->method('salvar');
        $this->eventoRepository->expects($this->once())->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new RegistrarPagamentoInput();
        $input->casoId = 60;
        $input->data = new \DateTimeImmutable('2026-07-20'); // 30 dias após o vencimento.
        $input->valorPago = 10300; // exigível-BASE exato; exigível-OVERLAY é 10500.
        $input->alocarManualmente = true;
        $input->alocacoes = [$this->alocacao(9, 10300)];

        $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertFalse($obrigacao->estaLiquidada(), 'exigível com a taxa PRÓPRIA (105,00) ainda não foi coberto pelos 103,00 pagos');
    }

    #[Test]
    public function autoBloqueiaSobrepagamento(): void
    {
        // Auto: dívida (5000) excede o saldo exigível (3000) → PagamentoExcedeSaldoException, nada persiste.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, 50);
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)->setCaso($caso)
            ->setDescricao('Parcela')->setValorOriginal(3000)
            ->setVencimentoOriginal(new \DateTimeImmutable('-1 day'));
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($obrigacao, 7);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$obrigacao]);
        $this->pagamentoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = new RegistrarPagamentoInput();
        $input->casoId = 50;
        $input->data = new \DateTimeImmutable('2026-04-15');
        $input->valorPago = 5000;

        $this->expectException(PagamentoExcedeSaldoException::class);

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }

    /**
     * @param AlocacaoPagamentoInput[] $alocacoes
     */
    private function novoInput(int $casoId, int $valorPago, array $alocacoes): RegistrarPagamentoInput
    {
        $input = new RegistrarPagamentoInput();
        $input->casoId = $casoId;
        $input->data = new \DateTimeImmutable('2026-04-15');
        $input->valorPago = $valorPago;
        $input->alocarManualmente = true; // os testes deste helper exercitam o modo MANUAL.
        $input->alocacoes = $alocacoes;

        return $input;
    }

    private function alocacao(int $obrigacaoId, int $valor): AlocacaoPagamentoInput
    {
        $item = new AlocacaoPagamentoInput();
        $item->obrigacaoId = $obrigacaoId;
        $item->valor = $valor;

        return $item;
    }
}
