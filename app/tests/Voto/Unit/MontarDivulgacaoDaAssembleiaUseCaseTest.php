<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\EnderecoPublicoDaAssembleia;
use App\Voto\UseCase\MontarDivulgacaoDaAssembleiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MontarDivulgacaoDaAssembleiaUseCase::class)]
final class MontarDivulgacaoDaAssembleiaUseCaseTest extends TestCase
{
    public function testLinkCanonicoNaoContemCredencialIndividual(): void
    {
        $tenant = new Tenant();
        $a = new Assembleia($tenant, 'AGO', PesoDoVoto::Unidade, 'Gestora');
        (new \ReflectionProperty(Assembleia::class, 'estado'))->setValue($a, EstadoDaAssembleia::Publicada);
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->expects(self::once())->method('buscarDoEscritorio')->with(10, $tenant)->willReturn($a);
        $tela = (new MontarDivulgacaoDaAssembleiaUseCase($repo, new EnderecoPublicoDaAssembleia('https://bluejus.com.br/')))->executar($tenant, 10);

        self::assertSame('https://bluejus.com.br/voto/v/' . $a->getCodigoPublico(), $tela->link);
        self::assertSame('AGO', $tela->titulo);
        self::assertStringNotContainsString('/votar/', $tela->link);
    }

    public function testOutroEscritorioNaoGanhaLink(): void
    {
        $this->expectException(AssembleiaNaoEncontradaException::class);
        $repo = $this->createStub(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn(null);
        (new MontarDivulgacaoDaAssembleiaUseCase($repo, new EnderecoPublicoDaAssembleia('https://bluejus.com.br')))->executar(new Tenant(), 10);
    }
}
