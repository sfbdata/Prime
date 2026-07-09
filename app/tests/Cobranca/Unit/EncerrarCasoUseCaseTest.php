<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EncerrarCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\SaldoNaoResolvidoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\EncerrarCasoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EncerrarCasoUseCase::class)]
final class EncerrarCasoUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private CalculadoraSaldo&MockObject $calculadoraSaldo;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private EncerrarCasoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        // CalculadoraSaldo é não-final: mockável para injetar o saldo derivado.
        $this->calculadoraSaldo = $this->createMock(CalculadoraSaldo::class);
        // O serviço de evento é final: usa-se o REAL com o repositório de eventos mockado (flush único).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new EncerrarCasoUseCase(
            $this->casoRepository,
            $this->calculadoraSaldo,
            $registrarEvento,
        );
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function encerraCasoAtivoComSaldoZero(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(50, $this->tenant)
            ->willReturn($caso);

        $this->calculadoraSaldo
            ->expects($this->once())
            ->method('saldoExigivel')
            ->with($caso)
            ->willReturn(0);

        // Caso persistido sem flush; o evento fecha a transação com flush: true.
        $this->casoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(CasoCobranca::class));
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new EncerrarCasoInput();
        $input->casoId = 50;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($caso, $resultado);
        self::assertSame(StatusCaso::Encerrado, $resultado->getStatus());
        self::assertTrue($resultado->estaEncerrado());
    }

    #[Test]
    public function encerraCasoJudicializadoComSaldoZero(): void
    {
        // O encerramento funciona tanto de Ativo quanto de Judicializado (SPEC §17).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Judicializado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(0);

        $this->casoRepository->expects($this->once())->method('salvar');
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new EncerrarCasoInput();
        $input->casoId = 50;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(StatusCaso::Encerrado, $resultado->getStatus());
    }

    #[Test]
    public function rejeitaSaldoNaoResolvido(): void
    {
        // Saldo exigível em aberto: não encerra e nada é salvo (invariável 17).
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(15000);

        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(SaldoNaoResolvidoException::class);

        $input = new EncerrarCasoInput();
        $input->casoId = 50;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaCasoJaEncerrado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Encerrado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->calculadoraSaldo->expects($this->never())->method('saldoExigivel');
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $input = new EncerrarCasoInput();
        $input->casoId = 50;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaCasoNaoEncontrado(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->calculadoraSaldo->expects($this->never())->method('saldoExigivel');
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $input = new EncerrarCasoInput();
        $input->casoId = 999;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
