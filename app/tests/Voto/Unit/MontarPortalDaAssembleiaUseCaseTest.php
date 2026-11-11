<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\UseCase\MontarPortalDaAssembleiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MontarPortalDaAssembleiaUseCase::class)]
final class MontarPortalDaAssembleiaUseCaseTest extends TestCase
{
    public function testMostraSomenteTituloECodigoDaPublicadaLiberada(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO Ipê', PesoDoVoto::Unidade, 'Gestora');
        $estado = new \ReflectionProperty(Assembleia::class, 'estado');
        $estado->setValue($assembleia, EstadoDaAssembleia::Publicada);
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->expects(self::once())->method('buscarPublicadaPeloCodigo')->with($assembleia->getCodigoPublico())->willReturn($assembleia);
        $liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $liberacao = new LiberacaoDoVoto($tenant);
        $liberacao->liberar();
        $liberacoes->method('findDoTenantParaOMaster')->with($tenant)->willReturn($liberacao);

        $portal = (new MontarPortalDaAssembleiaUseCase($repo, $liberacoes))->executar($assembleia->getCodigoPublico());

        self::assertNotNull($portal);
        self::assertSame('AGO Ipê', $portal->titulo);
        self::assertSame($assembleia->getCodigoPublico(), $portal->codigo);
        self::assertSame(['titulo', 'codigo'], array_keys(get_object_vars($portal)));
    }

    public function testSemLiberacaoNaoMostraAssembleia(): void
    {
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarPublicadaPeloCodigo')->willReturn(new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora'));
        $liberacoes = $this->createMock(LiberacaoDoVotoRepository::class);
        $liberacoes->method('findDoTenantParaOMaster')->willReturn(null);

        self::assertNull((new MontarPortalDaAssembleiaUseCase($repo, $liberacoes))->executar(str_repeat('a', 32)));
    }

    public function testCodigoInvalidoNaoConsultaBanco(): void
    {
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->expects(self::never())->method('buscarPublicadaPeloCodigo');

        self::assertNull((new MontarPortalDaAssembleiaUseCase($repo, $this->createStub(LiberacaoDoVotoRepository::class)))->executar('invalido'));
    }

    public function testCodigoPublicoEUnicoEPermaneceAoEditar(): void
    {
        $a = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        $b = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        $codigo = $a->getCodigoPublico();
        $a->editar('Novo título', PesoDoVoto::Fracao);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $codigo);
        self::assertNotSame($codigo, $b->getCodigoPublico());
        self::assertSame($codigo, $a->getCodigoPublico());
    }
}
