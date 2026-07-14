<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoComPagamentoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\ExcluirObrigacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirObrigacaoUseCase::class)]
final class ExcluirObrigacaoUseCaseTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private ExcluirObrigacaoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new ExcluirObrigacaoUseCase($this->obrigacaoRepository, $this->alocacaoRepository, $registrarEvento);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    private function obrigacaoAtiva(): Obrigacao
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        return (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao('Duplicada')
            ->setValorOriginal(10000)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-01-10'));
    }

    #[Test]
    public function excluiRegistrandoEventoComSnapshot(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->with(5, $this->tenant)->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);

        $capturado = null;
        $this->eventoRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(function (EventoHistorico $e) use (&$capturado): void {
                $capturado = $e;
            });
        // O evento (snapshot) é registrado ANTES; o flush do remover commita os dois.
        $this->obrigacaoRepository->expects($this->once())->method('remover')->with($obrigacao, true);

        $this->sut->executar(5, '  duplicidade  ', $this->tenant, $this->usuario);

        self::assertInstanceOf(EventoHistorico::class, $capturado);
        self::assertSame(TipoEventoHistorico::ObrigacaoExcluida, $capturado->getTipo());
        self::assertSame('Obrigação excluída: duplicidade', $capturado->getDescricao());
        $dados = $capturado->getDados();
        self::assertSame('Duplicada', $dados['descricao']);
        self::assertSame(10000, $dados['valorOriginal']);
        self::assertSame('duplicidade', $dados['motivo']);
    }

    #[Test]
    public function rejeitaObrigacaoDeOutroTenant(): void
    {
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->eventoRepository->expects($this->never())->method('salvar');
        $this->obrigacaoRepository->expects($this->never())->method('remover');

        $this->expectException(ObrigacaoNaoEncontradaException::class);

        $this->sut->executar(999, 'x', $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaExclusaoEmCasoEncerrado(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $obrigacao->getCaso()->setStatus(StatusCaso::Encerrado);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->obrigacaoRepository->expects($this->never())->method('remover');

        $this->expectException(CasoEncerradoException::class);

        $this->sut->executar(5, 'x', $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaExclusaoDeObrigacaoDeAcordoVigente(): void
    {
        $obrigacao = $this->obrigacaoAtiva()->setAcordoOrigem((new Acordo())->setStatus(StatusAcordo::Ativo));
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->obrigacaoRepository->expects($this->never())->method('remover');

        $this->expectException(ObrigacaoDeAcordoException::class);

        $this->sut->executar(5, 'x', $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaExclusaoDeObrigacaoSubstituidaPorAcordoVigente(): void
    {
        $obrigacao = $this->obrigacaoAtiva()->setAcordoSubstituto((new Acordo())->setStatus(StatusAcordo::Ativo));
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->obrigacaoRepository->expects($this->never())->method('remover');

        $this->expectException(ObrigacaoDeAcordoException::class);

        $this->sut->executar(5, 'x', $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaExclusaoComPagamentoAlocado(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(5000);
        $this->obrigacaoRepository->expects($this->never())->method('remover');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoComPagamentoException::class);

        $this->sut->executar(5, 'x', $this->tenant, $this->usuario);
    }
}
