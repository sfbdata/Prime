<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AlocacaoPagamentoInput;
use App\Cobranca\DTO\RegistrarPagamentoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
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
use App\Cobranca\Service\CalculadoraHonorarios;
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
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private RegistrarPagamentoUseCase $sut;
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->pagamentoRepository = $this->createMock(PagamentoRepository::class);
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        // AlocadorPagamento, AutoAlocadorFifo e CalculadoraHonorarios são finais e puros: usa-se os REAIS.
        $calculadora = new CalculadoraHonorarios();
        $alocador = new AlocadorPagamento($this->obrigacaoRepository, $calculadora);
        $autoAlocador = new AutoAlocadorFifo(
            $this->obrigacaoRepository,
            $this->createMock(AlocacaoPagamentoRepository::class),
            $this->createMock(LiquidacaoRepository::class),
            $calculadora,
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
        );
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function registraPagamentoAcrescidoDividaRateandoHonorarios(): void
    {
        // Caso 10% acrescido_divida: bruto 1100 → dívida 1000 + honorários 100.
        $caso = (new CasoCobranca())
            ->setTenant($this->tenant)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('10.00');
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
            $this->novoInput(50, 1100, [$this->alocacao(5, 1000)]),
            $this->tenant,
            $this->criadoPor,
        );

        self::assertSame(1000, $pagamento->getValorDivida());
        self::assertSame(0, $pagamento->getValorEncargos());
        self::assertSame(100, $pagamento->getValorHonorarios());
        self::assertCount(1, $pagamento->getAlocacoes());
        self::assertSame($this->tenant, $pagamento->getTenant());
        self::assertSame($caso, $pagamento->getCaso());
        self::assertSame($this->criadoPor, $pagamento->getCriadoPor());
        // Bruto recebido = dívida + honorários.
        self::assertSame(1100, $pagamento->valorTotalRecebido());
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
