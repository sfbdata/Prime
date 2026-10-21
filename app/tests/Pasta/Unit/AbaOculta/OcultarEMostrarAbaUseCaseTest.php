<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\AbaOculta;

use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Exception\AbaOcultaRecusadaException;
use App\Pasta\UseCase\MostrarAbaParaTodosUseCase;
use App\Pasta\UseCase\OcultarAbaParaTodosUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(OcultarAbaParaTodosUseCase::class)]
#[CoversClass(MostrarAbaParaTodosUseCase::class)]
#[Group('pasta')]
final class OcultarEMostrarAbaUseCaseTest extends TestCase
{
    use CriaCenarioDeAbaOcultaTrait;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    private function ocultar(): OcultarAbaParaTodosUseCase
    {
        return new OcultarAbaParaTodosUseCase($this->regras, $this->visibilidade(), $this->relogio());
    }

    private function mostrar(): MostrarAbaParaTodosUseCase
    {
        return new MostrarAbaParaTodosUseCase($this->regras, $this->visibilidade());
    }

    #[TestDox('Ocultar para todos sem regra: cria "toda a equipe", com autoria e hora, e devolve o aviso do desenho')]
    public function testOcultarParaTodosCria(): void
    {
        $this->regras->method('daAba')->willReturn(null);
        $salva = null;
        $this->regras->expects(self::once())->method('salvar')->willReturnCallback(static function (AbaOcultaDaPasta $r) use (&$salva): void {
            $salva = $r;
        });

        $saida = $this->ocultar()->executar($this->pasta, AbaDaPasta::Financeiro, $this->primario, $this->tenant);

        self::assertInstanceOf(AbaOcultaDaPasta::class, $salva);
        self::assertTrue($salva->isParaTodos());
        self::assertSame([], $salva->getUsuarios());
        self::assertSame($this->primario, $salva->getAtualizadoPor());
        self::assertSame(self::AGORA, $salva->getAtualizadoEm()->format('Y-m-d H:i:s'));
        self::assertSame(
            ['sucesso' => true, 'aba' => 'financeiro', 'rotulo' => 'Financeiro', 'paraTodos' => true, 'quantidade' => 0, 'mensagem' => 'Financeiro oculta para toda a equipe'],
            $saida->paraJson(),
        );
    }

    #[TestDox('Ocultar para todos com lista: a lista vira "toda a equipe" (a regra é a mesma linha)')]
    public function testOcultarParaTodosSubstituiLista(): void
    {
        $regra = $this->regraPara(AbaDaPasta::Push, [3]);
        $this->regras->method('daAba')->willReturn($regra);
        $this->regras->expects(self::once())->method('salvar')->with(self::identicalTo($regra));

        $this->ocultar()->executar($this->pasta, AbaDaPasta::Push, $this->master, $this->tenant);

        self::assertTrue($regra->isParaTodos());
        self::assertSame([], $regra->getUsuarios());
        self::assertSame($this->master, $regra->getAtualizadoPor());
    }

    #[TestDox('Quem não é primário nem Master não oculta: exceção e nada gravado')]
    public function testComumNaoOculta(): void
    {
        $this->regras->expects(self::never())->method('salvar');

        $this->expectException(AbaOcultaRecusadaException::class);
        $this->expectExceptionMessage('Só o usuário primário pode ocultar abas');
        $this->ocultar()->executar($this->pasta, AbaDaPasta::Push, $this->ana, $this->tenant);
    }

    #[TestDox('Mostrar para todos: apaga a regra e devolve "<Rótulo> visível para todos"')]
    public function testMostrarApaga(): void
    {
        $regra = $this->regraParaTodos(AbaDaPasta::Push);
        $this->regras->method('daAba')->willReturn($regra);
        $this->regras->expects(self::once())->method('remover')->with(self::identicalTo($regra), true);

        $saida = $this->mostrar()->executar($this->pasta, AbaDaPasta::Push, $this->primario, $this->tenant);

        self::assertSame('Push Processual visível para todos', $saida->mensagem);
        self::assertFalse($saida->paraTodos);
    }

    #[TestDox('Mostrar sem regra: nada a apagar, mesma resposta')]
    public function testMostrarSemRegra(): void
    {
        $this->regras->method('daAba')->willReturn(null);
        $this->regras->expects(self::never())->method('remover');

        self::assertSame('Metas visível para todos', $this->mostrar()->executar($this->pasta, AbaDaPasta::Tarefas, $this->primario, $this->tenant)->mensagem);
    }

    #[TestDox('Mostrar: quem não configura recebe exceção')]
    public function testMostrarComumRecusa(): void
    {
        $this->regras->expects(self::never())->method('remover');

        $this->expectException(AbaOcultaRecusadaException::class);
        $this->mostrar()->executar($this->pasta, AbaDaPasta::Push, $this->beto, $this->tenant);
    }
}
