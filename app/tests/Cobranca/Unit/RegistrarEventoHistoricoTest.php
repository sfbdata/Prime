<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrarEventoHistorico::class)]
final class RegistrarEventoHistoricoTest extends TestCase
{
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private RegistrarEventoHistorico $sut;

    protected function setUp(): void
    {
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $this->sut = new RegistrarEventoHistorico($this->eventoRepository);
    }

    #[Test]
    public function registraEventoComTenantDoCasoEPersisteSemFlush(): void
    {
        $tenant = new Tenant();
        $usuario = new User();
        $caso = (new CasoCobranca())->setTenant($tenant);

        // Persiste na unidade de trabalho do UseCase — sem flush próprio (o UseCase flusha).
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class));

        $evento = $this->sut->registrar(
            $caso,
            TipoEventoHistorico::CasoAberto,
            $usuario,
            'Caso aberto',
            ['origem' => 'teste'],
        );

        self::assertSame($tenant, $evento->getTenant());
        self::assertSame($caso, $evento->getCaso());
        self::assertSame(TipoEventoHistorico::CasoAberto, $evento->getTipo());
        self::assertSame($usuario, $evento->getUsuario());
        self::assertSame('Caso aberto', $evento->getDescricao());
        self::assertSame(['origem' => 'teste'], $evento->getDados());
    }

    #[Test]
    public function usuarioEDadosSaoOpcionais(): void
    {
        $caso = (new CasoCobranca())->setTenant(new Tenant());
        $this->eventoRepository->expects($this->once())->method('salvar');

        $evento = $this->sut->registrar($caso, TipoEventoHistorico::ObrigacaoCriada, null, 'Obrigação criada');

        self::assertNull($evento->getUsuario());
        self::assertNull($evento->getDados());
    }

    #[Test]
    public function lancaQuandoCasoNaoTemTenantENaoPersiste(): void
    {
        $caso = new CasoCobranca(); // sem tenant

        $this->eventoRepository->expects($this->never())->method('salvar');
        $this->expectException(\LogicException::class);

        $this->sut->registrar($caso, TipoEventoHistorico::CasoAberto, null, 'x');
    }
}
