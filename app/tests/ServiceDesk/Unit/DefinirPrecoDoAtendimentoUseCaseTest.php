<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Unit;

use App\ServiceDesk\Entity\PrecoDoAtendimento;
use App\ServiceDesk\Enum\TipoDeChamado;
use App\ServiceDesk\Repository\PrecoDoAtendimentoRepository;
use App\ServiceDesk\UseCase\DefinirPrecoDoAtendimentoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinirPrecoDoAtendimentoUseCase::class)]
final class DefinirPrecoDoAtendimentoUseCaseTest extends TestCase
{
    public function testCriaQuandoNaoHaPreco(): void
    {
        $repo = $this->createMock(PrecoDoAtendimentoRepository::class);
        $repo->method('doTipo')->willReturn(null);
        $repo->expects(self::once())->method('salvar')->with(self::callback(
            static fn (PrecoDoAtendimento $p): bool => 6_000 === $p->getValorCentavos() && TipoDeChamado::Agenda === $p->getTipo(),
        ), true);

        (new DefinirPrecoDoAtendimentoUseCase($repo))->executar(TipoDeChamado::Agenda, 6_000);
    }

    public function testAlteraOExistente(): void
    {
        $existente = new PrecoDoAtendimento(TipoDeChamado::Suporte, 2_000);
        $repo = $this->createMock(PrecoDoAtendimentoRepository::class);
        $repo->method('doTipo')->willReturn($existente);
        $repo->expects(self::once())->method('salvar');

        (new DefinirPrecoDoAtendimentoUseCase($repo))->executar(TipoDeChamado::Suporte, 3_000);

        self::assertSame(3_000, $existente->getValorCentavos());
    }

    public function testNuloRemoveOPreco(): void
    {
        $existente = new PrecoDoAtendimento(TipoDeChamado::Suporte, 2_000);
        $repo = $this->createMock(PrecoDoAtendimentoRepository::class);
        $repo->method('doTipo')->willReturn($existente);
        $repo->expects(self::once())->method('remover')->with($existente, true);

        (new DefinirPrecoDoAtendimentoUseCase($repo))->executar(TipoDeChamado::Suporte, null);
    }

    public function testNuloSemPrecoNaoFazNada(): void
    {
        $repo = $this->createMock(PrecoDoAtendimentoRepository::class);
        $repo->method('doTipo')->willReturn(null);
        $repo->expects(self::never())->method('remover');
        $repo->expects(self::never())->method('salvar');

        (new DefinirPrecoDoAtendimentoUseCase($repo))->executar(TipoDeChamado::Suporte, null);
    }
}
