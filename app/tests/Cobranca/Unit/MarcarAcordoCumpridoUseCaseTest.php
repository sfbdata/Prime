<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\MarcarAcordoCumpridoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\MarcarAcordoCumpridoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcarAcordoCumpridoUseCase::class)]
final class MarcarAcordoCumpridoUseCaseTest extends TestCase
{
    private AcordoRepository&MockObject $acordoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private MarcarAcordoCumpridoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->acordoRepository = $this->createMock(AcordoRepository::class);
        // O serviço é final: usa-se o REAL com o repositório de eventos mockado (flush único).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new MarcarAcordoCumpridoUseCase($this->acordoRepository, $registrarEvento);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function marcaAcordoAtivoComoCumpridoComEvento(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);

        $this->acordoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(90, $this->tenant)
            ->willReturn($acordo);

        // Acordo persistido sem flush; o evento fecha a transação com flush: true.
        $this->acordoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(Acordo::class));
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new MarcarAcordoCumpridoInput();
        $input->acordoId = 90;

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($acordo, $resultado);
        // Cumprido é estado VIGENTE: transiciona sem reverter saldo (derivado pela calculadora).
        self::assertSame(StatusAcordo::Cumprido, $resultado->getStatus());
        self::assertFalse($resultado->estaAtivo());
    }

    #[Test]
    public function rejeitaAcordoNaoEncontrado(): void
    {
        // Acordo inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(AcordoNaoEncontradoException::class);

        $input = new MarcarAcordoCumpridoInput();
        $input->acordoId = 999;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaAcordoNaoAtivo(): void
    {
        // Acordo já cancelado não transiciona (só um acordo ativo é marcado cumprido).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $acordo->cancelar('Cancelado antes');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(AcordoNaoAtivoException::class);

        $input = new MarcarAcordoCumpridoInput();
        $input->acordoId = 90;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
