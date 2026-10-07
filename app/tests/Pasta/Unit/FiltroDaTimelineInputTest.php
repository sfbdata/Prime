<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\FiltroDaTimelineInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(FiltroDaTimelineInput::class)]
final class FiltroDaTimelineInputTest extends TestCase
{
    #[TestDox('Sem parâmetros: tudo, todo o período, limite padrão')]
    public function testPadrao(): void
    {
        $filtro = FiltroDaTimelineInput::daRequisicao(new Request());

        self::assertSame('tudo', $filtro->categoria);
        self::assertSame('tudo', $filtro->periodo);
        self::assertNull($filtro->pessoa);
        self::assertSame('', $filtro->busca);
        self::assertSame(FiltroDaTimelineInput::LIMITE_PADRAO, $filtro->limite);
    }

    #[TestDox('Valores válidos passam; categoria e período desconhecidos viram o neutro')]
    public function testValidacao(): void
    {
        $valido = FiltroDaTimelineInput::daRequisicao(new Request([
            'categoria' => 'documento', 'periodo' => 'semana', 'pessoa' => ' Ana ', 'q' => ' contrato ',
        ]));
        self::assertSame('documento', $valido->categoria);
        self::assertSame('semana', $valido->periodo);
        self::assertSame('Ana', $valido->pessoa);
        self::assertSame('contrato', $valido->busca);

        $invalido = FiltroDaTimelineInput::daRequisicao(new Request(['categoria' => 'ia', 'periodo' => 'ano']));
        self::assertSame('tudo', $invalido->categoria);
        self::assertSame('tudo', $invalido->periodo);
    }

    #[TestDox('Limite fica entre 1 e o máximo')]
    public function testLimite(): void
    {
        self::assertSame(FiltroDaTimelineInput::LIMITE_MAXIMO, FiltroDaTimelineInput::daRequisicao(new Request(['limite' => '99999']))->limite);
        self::assertSame(1, FiltroDaTimelineInput::daRequisicao(new Request(['limite' => '-3']))->limite);
        self::assertSame(50, FiltroDaTimelineInput::daRequisicao(new Request(['limite' => '50']))->limite);
    }
}
