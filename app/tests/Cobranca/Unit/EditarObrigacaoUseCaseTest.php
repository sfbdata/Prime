<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarObrigacaoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\ValorAbaixoDoAlocadoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\EditarObrigacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarObrigacaoUseCase::class)]
final class EditarObrigacaoUseCaseTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private EditarObrigacaoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        // O serviço é final (não mockável): usa-se o REAL com o repositório de eventos mockado.
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new EditarObrigacaoUseCase($this->obrigacaoRepository, $this->alocacaoRepository, $registrarEvento);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    private function obrigacaoAtiva(): Obrigacao
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        return (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao('Boleto errado')
            ->setValorOriginal(10000)
            ->setEncargosReconhecidos(0)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-01-10'))
            ->setReferenciaExterna('REF-OLD');
    }

    private function inputValido(): EditarObrigacaoInput
    {
        $input = new EditarObrigacaoInput();
        $input->obrigacaoId = 5;
        $input->descricao = '  Boleto correto  ';
        $input->valorOriginal = 25000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2026-03-15');
        $input->referenciaExterna = '  REF-NEW  ';
        $input->encargosReconhecidos = 500;
        $input->motivo = '  valor digitado errado  ';

        return $input;
    }

    #[Test]
    public function editaTodosOsCamposERegistraEventoComAntesEDepois(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->with(5, $this->tenant)->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);

        $capturado = null;
        $this->eventoRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(function (EventoHistorico $e, bool $flush) use (&$capturado): void {
                $capturado = $e;
                self::assertTrue($flush, 'o evento fecha a transação com flush');
            });

        $resultado = $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);

        // Campos corrigidos (com trim de descrição/referência).
        self::assertSame('Boleto correto', $resultado->getDescricao());
        self::assertSame(25000, $resultado->getValorOriginal());
        self::assertSame('2026-03-15', $resultado->getVencimentoOriginal()->format('Y-m-d'));
        self::assertSame(500, $resultado->getEncargosReconhecidos());
        self::assertSame('REF-NEW', $resultado->getReferenciaExterna());
        self::assertSame(25500, $resultado->valorExigivel());

        // Evento com antes/depois e motivo.
        self::assertInstanceOf(EventoHistorico::class, $capturado);
        self::assertSame(TipoEventoHistorico::ObrigacaoEditada, $capturado->getTipo());
        self::assertSame('Obrigação corrigida: valor digitado errado', $capturado->getDescricao());
        $dados = $capturado->getDados();
        self::assertSame(10000, $dados['antes']['valorOriginal']);
        self::assertSame(25000, $dados['depois']['valorOriginal']);
        self::assertSame('REF-OLD', $dados['antes']['referenciaExterna']);
        self::assertSame('valor digitado errado', $dados['motivo']);
    }

    #[Test]
    public function rejeitaObrigacaoDeOutroTenant(): void
    {
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoNaoEncontradaException::class);

        $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaEdicaoEmCasoEncerrado(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $obrigacao->getCaso()->setStatus(StatusCaso::Encerrado);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaEdicaoDeParcelaDeAcordoVigente(): void
    {
        $obrigacao = $this->obrigacaoAtiva()->setAcordoOrigem((new Acordo())->setStatus(StatusAcordo::Ativo));
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoDeAcordoException::class);

        $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaEdicaoDeObrigacaoSubstituidaPorAcordoVigente(): void
    {
        $obrigacao = $this->obrigacaoAtiva()->setAcordoSubstituto((new Acordo())->setStatus(StatusAcordo::Ativo));
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoDeAcordoException::class);

        $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
    }

    #[Test]
    public function permiteEdicaoQuandoOAcordoFoiCancelado(): void
    {
        // A original voltou ao saldo (acordo substituto CANCELADO) → travava antes do fix, editável agora.
        $obrigacao = $this->obrigacaoAtiva()->setAcordoSubstituto((new Acordo())->setStatus(StatusAcordo::Cancelado));
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(0);
        $this->eventoRepository->expects($this->once())->method('salvar');

        $resultado = $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);

        self::assertSame(25000, $resultado->getValorOriginal(), 'obrigação de acordo cancelado volta a ser editável');
    }

    #[Test]
    public function rejeitaValorExigivelAbaixoDoAlocado(): void
    {
        $obrigacao = $this->obrigacaoAtiva();
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        // Já foi pago/alocado R$ 300,00 nesta obrigação; o novo exigível (R$ 255,00) fica abaixo.
        $this->alocacaoRepository->method('totalAlocadoEmObrigacoes')->willReturn(30000);
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ValorAbaixoDoAlocadoException::class);

        $this->sut->executar($this->inputValido(), $this->tenant, $this->usuario);
    }
}
