<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\CancelarAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\CancelarAcordoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CancelarAcordoUseCase::class)]
final class CancelarAcordoUseCaseTest extends TestCase
{
    private AcordoRepository&MockObject $acordoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private CancelarAcordoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->acordoRepository = $this->createMock(AcordoRepository::class);
        // O serviço é final: usa-se o REAL com o repositório de eventos mockado (flush único).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new CancelarAcordoUseCase($this->acordoRepository, $registrarEvento);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function cancelaAcordoAtivoComMotivo(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);

        $this->acordoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(85, $this->tenant)
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

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;
        $input->motivo = '  Firmado por engano  ';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($acordo, $resultado);
        self::assertSame(StatusAcordo::Cancelado, $resultado->getStatus());
        self::assertFalse($resultado->estaAtivo());
        // Motivo normalizado (trim) e registrado no acordo.
        self::assertSame('Firmado por engano', $resultado->getMotivoCancelamento());
    }

    #[Test]
    public function cancelaAcordoAtivoSemMotivo(): void
    {
        // Motivo é OPCIONAL no cancelamento (diferente do rompimento): ausente vira null.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->acordoRepository->expects($this->once())->method('salvar');
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;
        // Sem motivo informado (default null).

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(StatusAcordo::Cancelado, $resultado->getStatus());
        self::assertNull($resultado->getMotivoCancelamento());
    }

    #[Test]
    public function rejeitaAcordoNaoEncontrado(): void
    {
        // Acordo inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(AcordoNaoEncontradoException::class);

        $input = new CancelarAcordoInput();
        $input->acordoId = 999;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaAcordoNaoAtivo(): void
    {
        // Acordo já rompido não transiciona (só um acordo ativo cancela).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $acordo->romper('Rompido antes');

        $this->acordoRepository->method('findOneByIdDoTenant')->willReturn($acordo);
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(AcordoNaoAtivoException::class);

        $input = new CancelarAcordoInput();
        $input->acordoId = 85;

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
