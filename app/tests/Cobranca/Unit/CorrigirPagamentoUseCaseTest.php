<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AlocacaoPagamentoInput;
use App\Cobranca\DTO\CorrigirPagamentoInput;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\PagamentoNaoEncontradoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
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
use App\Cobranca\UseCase\CorrigirPagamentoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorrigirPagamentoUseCase::class)]
final class CorrigirPagamentoUseCaseTest extends TestCase
{
    private PagamentoRepository&MockObject $pagamentoRepository;
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private CorrigirPagamentoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->pagamentoRepository = $this->createMock(PagamentoRepository::class);
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        // AlocadorPagamento, AutoAlocadorFifo e CalculadoraHonorarios são finais e puros: usa-se os REAIS.
        $calculadora = new CalculadoraHonorarios();
        $alocador = new AlocadorPagamento($this->obrigacaoRepository, $calculadora);
        $autoAlocador = new AutoAlocadorFifo(
            $this->obrigacaoRepository,
            $this->alocacaoRepository,
            $this->createMock(LiquidacaoRepository::class),
            $calculadora,
            new EncargosVivos(new MockClock(new \DateTimeImmutable('2026-07-20')), new CalculadoraEncargos()),
            new ResolvedorConfigEncargos(),
        );
        // RegistrarEventoHistorico é final: usa-se o REAL com o repositório de eventos mockado.
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new CorrigirPagamentoUseCase(
            $this->pagamentoRepository,
            $alocador,
            $autoAlocador,
            $registrarEvento,
            $this->alocacaoRepository,
            new ResolvedorConfigEncargos(),
            new ReconciliadorLiquidacao(new CalculadoraEncargos()),
        );
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function corrigePagamentoReescrevendoComposicaoEAlocacoes(): void
    {
        // sem_percentual: bruto 5000 → dívida 5000 numa única obrigação do mesmo caso.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);

        // Pagamento anterior: composição 3000 + 1 alocação antiga que deve ser descartada.
        $alocacaoAntiga = (new AlocacaoPagamento())->setTenant($this->tenant)->setObrigacao($obrigacao)->setValor(3000);
        $pagamento = new Pagamento();
        $pagamento->setTenant($this->tenant);
        $pagamento->setCaso($caso);
        $pagamento->setValorDivida(3000);
        $pagamento->adicionarAlocacao($alocacaoAntiga);

        $this->pagamentoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(80, $this->tenant)
            ->willReturn($pagamento);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);

        // Persistência sem flush; o evento fecha a transação com flush: true.
        $this->pagamentoRepository->expects($this->once())->method('salvar')->with($pagamento);
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $novaData = new \DateTimeImmutable('2026-05-20');
        $resultado = $this->sut->executar(
            $this->novoInput(80, 5000, [$this->alocacao(7, 5000)], '  Erro na distribuição  ', $novaData),
            $this->tenant,
            $this->usuario,
        );

        self::assertSame($pagamento, $resultado);
        self::assertSame(5000, $resultado->getValorDivida());
        self::assertSame(0, $resultado->getValorEncargos());
        self::assertSame(0, $resultado->getValorHonorarios());
        self::assertSame('Erro na distribuição', $resultado->getMotivoCorrecao());
        // Alocação antiga descartada; só a nova (5000) permanece na coleção.
        self::assertCount(1, $resultado->getAlocacoes());
        self::assertSame(5000, $resultado->getAlocacoes()->first()->getValor());
        // Data opcional informada foi aplicada.
        self::assertSame($novaData, $resultado->getData());
    }

    #[Test]
    public function rejeitaPagamentoNaoEncontrado(): void
    {
        // Inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->pagamentoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->pagamentoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(PagamentoNaoEncontradoException::class);

        $this->sut->executar(
            $this->novoInput(999, 5000, [$this->alocacao(7, 5000)], 'Motivo'),
            $this->tenant,
            $this->usuario,
        );
    }

    #[Test]
    public function rejeitaCorrecaoEmCasoEncerrado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setStatus(StatusCaso::Encerrado);
        $pagamento = new Pagamento();
        $pagamento->setTenant($this->tenant);
        $pagamento->setCaso($caso);

        $this->pagamentoRepository->method('findOneByIdDoTenant')->willReturn($pagamento);
        $this->pagamentoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $this->sut->executar(
            $this->novoInput(80, 5000, [$this->alocacao(7, 5000)], 'Motivo'),
            $this->tenant,
            $this->usuario,
        );
    }

    #[Test]
    public function autoCorrigePorFifoExcluindoAsAlocacoesDoProprioPagamento(): void
    {
        // Obrigação exigível 50000, com 30000 já alocados — TODOS do próprio pagamento em correção.
        // Sem excluí-los, a sala (20000) < dívida (50000) e bloquearia por engano; excluindo, aloca 50000.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, 900);
        $obrigacao = (new Obrigacao())
            ->setTenant($this->tenant)->setCaso($caso)
            ->setDescricao('Parcela')->setValorOriginal(50000)
            ->setVencimentoOriginal(new \DateTimeImmutable('-1 day'));
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($obrigacao, 7);

        $alocacaoAntiga = (new AlocacaoPagamento())->setTenant($this->tenant)->setObrigacao($obrigacao)->setValor(30000);
        $pagamento = new Pagamento();
        $pagamento->setTenant($this->tenant);
        $pagamento->setCaso($caso);
        $pagamento->setValorDivida(30000);
        $pagamento->adicionarAlocacao($alocacaoAntiga);

        $this->pagamentoRepository->method('findOneByIdDoTenant')->willReturn($pagamento);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([$obrigacao]);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        // A query de saldo enxerga as 30000 do próprio pagamento; o UseCase as exclui da sala.
        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->with([900], $this->tenant)->willReturn([7 => 30000]);

        $this->pagamentoRepository->expects($this->once())->method('salvar');
        $this->eventoRepository->expects($this->once())->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new CorrigirPagamentoInput();
        $input->pagamentoId = 80;
        $input->valorPago = 50000; // auto (alocarManualmente = false).
        $input->motivoCorrecao = 'Redistribuir tudo na parcela';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(50000, $resultado->getValorDivida());
        self::assertCount(1, $resultado->getAlocacoes());
        self::assertSame(50000, $resultado->getAlocacoes()->first()->getValor());
    }

    /**
     * @param AlocacaoPagamentoInput[] $alocacoes
     */
    private function novoInput(
        int $pagamentoId,
        int $valorPago,
        array $alocacoes,
        string $motivo,
        ?\DateTimeImmutable $data = null,
    ): CorrigirPagamentoInput {
        $input = new CorrigirPagamentoInput();
        $input->pagamentoId = $pagamentoId;
        $input->data = $data;
        $input->valorPago = $valorPago;
        $input->alocarManualmente = true; // os testes deste helper exercitam o modo MANUAL.
        $input->alocacoes = $alocacoes;
        $input->motivoCorrecao = $motivo;

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
