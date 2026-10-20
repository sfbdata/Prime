<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\AbaOculta;

use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Exception\AbaOcultaRecusadaException;
use App\Pasta\UseCase\DefinirQuemNaoVeAbaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinirQuemNaoVeAbaUseCase::class)]
#[Group('pasta')]
final class DefinirQuemNaoVeAbaUseCaseTest extends TestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    private ?AbaOcultaDaPasta $salva = null;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    private function useCase(): DefinirQuemNaoVeAbaUseCase
    {
        return new DefinirQuemNaoVeAbaUseCase($this->regras, $this->visibilidade(), $this->equipe(), $this->relogio());
    }

    private function capturarSalvar(): void
    {
        $this->regras->expects(self::once())->method('salvar')->willReturnCallback(function (AbaOcultaDaPasta $r): void {
            $this->salva = $r;
        });
    }

    #[TestDox('Alguns: grava a lista e responde "<Rótulo> oculta para N pessoa(s)"')]
    public function testAlguns(): void
    {
        $this->regras->method('daAba')->willReturn(null);
        $this->capturarSalvar();

        $saida = $this->useCase()->executar($this->pasta, AbaDaPasta::Documentos, $this->primario, $this->tenant, [3]);

        self::assertFalse($this->salva?->isParaTodos());
        self::assertSame([3], $this->salva?->getUsuarios());
        self::assertSame('Documentos oculta para 1 pessoa(s)', $saida->mensagem);
        self::assertSame(1, $saida->quantidade);
        self::assertFalse($saida->paraTodos);
    }

    #[TestDox('Ids inelegíveis (primário, Master, de outro escritório ou inexistentes) são ignorados em silêncio')]
    public function testFiltraInelegiveis(): void
    {
        $this->regras->method('daAba')->willReturn(null);
        $this->capturarSalvar();

        $saida = $this->useCase()->executar($this->pasta, AbaDaPasta::Push, $this->primario, $this->tenant, [1, 2, 4, 5, 999, 4]);

        self::assertSame([4], $this->salva?->getUsuarios());
        self::assertSame(1, $saida->quantidade);
    }

    #[TestDox('Todos os elegíveis marcados: vira "toda a equipe" (vale também para quem entrar depois)')]
    public function testTodosViramParaTodos(): void
    {
        $this->regras->method('daAba')->willReturn(null);
        $this->capturarSalvar();

        $saida = $this->useCase()->executar($this->pasta, AbaDaPasta::Push, $this->master, $this->tenant, [4, 3]);

        self::assertTrue($this->salva?->isParaTodos());
        self::assertSame([], $this->salva?->getUsuarios());
        self::assertTrue($saida->paraTodos);
        self::assertSame('Push Processual oculta para 2 pessoa(s)', $saida->mensagem, 'o aviso do desenho conta pessoas (L.4426)');
    }

    #[TestDox('Ninguém (ou só inelegíveis): apaga a regra que existia e responde "visível para todos"')]
    public function testNenhumApaga(): void
    {
        $regra = $this->regraPara(AbaDaPasta::Push, [3]);
        $this->regras->method('daAba')->willReturn($regra);
        $this->regras->expects(self::never())->method('salvar');
        $this->regras->expects(self::once())->method('remover')->with(self::identicalTo($regra), true);

        $saida = $this->useCase()->executar($this->pasta, AbaDaPasta::Push, $this->primario, $this->tenant, [1, 2]);

        self::assertSame('Push Processual visível para todos', $saida->mensagem);
    }

    #[TestDox('Regra existente "toda a equipe" vira lista quando só alguns são marcados')]
    public function testParaTodosViraLista(): void
    {
        $regra = $this->regraParaTodos(AbaDaPasta::Financeiro);
        $this->regras->method('daAba')->willReturn($regra);
        $this->regras->expects(self::once())->method('salvar')->with(self::identicalTo($regra));

        $this->useCase()->executar($this->pasta, AbaDaPasta::Financeiro, $this->primario, $this->tenant, [3]);

        self::assertFalse($regra->isParaTodos());
        self::assertSame([3], $regra->getUsuarios());
    }

    #[TestDox('Quem não configura: exceção, nada gravado nem apagado')]
    public function testComumRecusa(): void
    {
        $this->regras->expects(self::never())->method('salvar');
        $this->regras->expects(self::never())->method('remover');

        $this->expectException(AbaOcultaRecusadaException::class);
        $this->useCase()->executar($this->pasta, AbaDaPasta::Push, $this->ana, $this->tenant, [4]);
    }
}
