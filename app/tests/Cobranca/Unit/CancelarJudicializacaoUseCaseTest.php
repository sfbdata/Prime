<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\CancelarJudicializacaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\CasoNaoJudicializadoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\CancelarJudicializacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CancelarJudicializacaoUseCase::class)]
final class CancelarJudicializacaoUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private CancelarJudicializacaoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $this->sut = new CancelarJudicializacaoUseCase(
            $this->casoRepository,
            new RegistrarEventoHistorico($this->eventoRepository),
        );
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function cancelaCasoJudicializadoComPastaVinculada(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Judicializado);
        $pasta = (new Pasta())->setTenant($this->tenant);
        $caso->setPastaJudicial($pasta);

        $this->casoRepository->method('findOneByIdDoTenant')->with(50, $this->tenant)->willReturn($caso);
        $this->casoRepository->expects($this->once())->method('salvar')->with(self::isInstanceOf(CasoCobranca::class));

        $evento = null;
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->willReturnCallback(function (EventoHistorico $e, bool $flush) use (&$evento): void {
                $evento = $e;
                self::assertTrue($flush);
            });

        $input = new CancelarJudicializacaoInput();
        $input->casoId = 50;
        $input->motivo = 'Pasta excluída por engano';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($caso, $resultado);
        self::assertSame(StatusCaso::Ativo, $resultado->getStatus());
        self::assertNull($resultado->getPastaJudicial());
        self::assertSame(TipoEventoHistorico::JudicializacaoCancelada, $evento->getTipo());
        self::assertSame('Judicialização cancelada: Pasta excluída por engano', $evento->getDescricao());
    }

    #[Test]
    public function cancelaCasoJudicializadoComPastaJaNula(): void
    {
        // O próprio Problema A: a pasta foi excluída e a FK já zerou (ON DELETE SET NULL), mas o
        // status ficou preso em Judicializado. O cancelamento tem de funcionar mesmo assim.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Judicializado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->casoRepository->expects($this->once())->method('salvar');
        $this->eventoRepository->expects($this->once())->method('salvar');

        $input = new CancelarJudicializacaoInput();
        $input->casoId = 50;
        $input->motivo = 'Pasta já excluída, status preso';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame(StatusCaso::Ativo, $resultado->getStatus());
        self::assertNull($resultado->getPastaJudicial());
    }

    #[Test]
    public function rejeitaCasoNaoEncontrado(): void
    {
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $input = new CancelarJudicializacaoInput();
        $input->casoId = 999;
        $input->motivo = 'Qualquer';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaCasoAtivoNuncaJudicializado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Ativo);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoJudicializadoException::class);

        $input = new CancelarJudicializacaoInput();
        $input->casoId = 50;
        $input->motivo = 'Qualquer';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }

    #[Test]
    public function rejeitaCasoEncerrado(): void
    {
        // Encerrado não é estaJudicializado() — o enum é de valor único — então cai na mesma guarda.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $caso->setStatus(StatusCaso::Encerrado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoJudicializadoException::class);

        $input = new CancelarJudicializacaoInput();
        $input->casoId = 50;
        $input->motivo = 'Qualquer';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
