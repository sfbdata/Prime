<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\ConfiguracaoDaPublicacao;
use App\Voto\UseCase\PublicarAssembleiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicarAssembleiaUseCase::class)]
#[CoversClass(ConfiguracaoDaPublicacao::class)]
final class PublicarAssembleiaUseCaseTest extends TestCase
{
    public function testConferirNaoPublicaEAConfirmacaoPublica(): void
    {
        $assembleia = $this->pronta();
        $assembleia->exigirPublicacaoPossivel();
        $codigo = ConfiguracaoDaPublicacao::codigo($assembleia);
        self::assertSame(EstadoDaAssembleia::Rascunho, $assembleia->getEstado());
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn($assembleia);
        $repo->expects(self::once())->method('salvar')->with($assembleia, true);
        (new PublicarAssembleiaUseCase($repo, self::serializacao()))->executar($assembleia->getTenant(), 1, conferencia: $codigo);
        self::assertSame(EstadoDaAssembleia::Publicada, $assembleia->getEstado());
    }

    public function testListaAlteradaDepoisDaConferenciaExigeNovaRevisao(): void
    {
        $assembleia = $this->pronta();
        $codigo = ConfiguracaoDaPublicacao::codigo($assembleia);
        new UnidadeDaAssembleia($assembleia, '102', str_repeat('c', 32), str_repeat('d', 64));
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn($assembleia);
        $repo->expects(self::never())->method('salvar');
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        (new PublicarAssembleiaUseCase($repo, self::serializacao()))->executar($assembleia->getTenant(), 1, conferencia: $codigo);
    }

    private function pronta(): Assembleia
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Mesa');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));

        return $assembleia;
    }
    private static function serializacao(): \App\Voto\Service\SerializacaoDaConfiguracao
    {
        return new \App\Voto\Service\SerializacaoDaConfiguracao(new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore()));
    }

}
