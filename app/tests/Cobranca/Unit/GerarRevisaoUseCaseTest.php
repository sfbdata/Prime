<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\GerarRevisaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\RevisaoPessoaCobrada;
use App\Cobranca\Enum\StatusRevisao;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\RevisaoPessoaCobradaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\GerarRevisaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(GerarRevisaoUseCase::class)]
final class GerarRevisaoUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private RevisaoPessoaCobradaRepository&MockObject $revisaoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private GerarRevisaoUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->revisaoRepository = $this->createMock(RevisaoPessoaCobradaRepository::class);
        // O serviço é final: usa-se o REAL com o repositório de eventos mockado (flush único).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new GerarRevisaoUseCase($this->casoRepository, $this->revisaoRepository, $registrarEvento);
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[Test]
    public function geraRevisaoPendenteComEvento(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);

        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(50, $this->tenant)
            ->willReturn($caso);

        // Revisão persistida sem flush; o evento fecha a transação com flush: true.
        $this->revisaoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(RevisaoPessoaCobrada::class));
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new GerarRevisaoInput();
        $input->casoId = 50;
        $input->motivo = 'Mudança de proprietário do imóvel';

        $resultado = $this->sut->executar($input, $this->tenant, $this->usuario);

        self::assertSame($caso, $resultado->getCaso());
        self::assertSame($this->tenant, $resultado->getTenant());
        self::assertSame('Mudança de proprietário do imóvel', $resultado->getMotivo());
        self::assertSame($this->usuario, $resultado->getCriadoPor());
        // Nasce pendente: alimenta o alerta de revisão até ser resolvida (§8).
        self::assertTrue($resultado->estaPendente());
        self::assertSame(StatusRevisao::Pendente, $resultado->getStatus());
    }

    #[Test]
    public function rejeitaCasoNaoEncontrado(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->revisaoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $input = new GerarRevisaoInput();
        $input->casoId = 999;
        $input->motivo = 'Qualquer';

        $this->sut->executar($input, $this->tenant, $this->usuario);
    }
}
