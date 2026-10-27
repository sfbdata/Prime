<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\DTO\PerguntarABlueJusIaInput;
use App\Inteligencia\Exception\PerguntaInvalidaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PerguntarABlueJusIaInput::class)]
final class PerguntarABlueJusIaInputTest extends TestCase
{
    #[TestDox('texto vazio ou só espaços é recusado')]
    public function testVazio(): void
    {
        $this->expectException(PerguntaInvalidaException::class);

        new PerguntarABlueJusIaInput("  \n\t ");
    }

    #[TestDox('2001 caracteres é recusado; 2000 passa')]
    public function testLimite(): void
    {
        self::assertSame(2000, mb_strlen((new PerguntarABlueJusIaInput(str_repeat('á', 2000)))->texto));

        $this->expectException(PerguntaInvalidaException::class);

        new PerguntarABlueJusIaInput(str_repeat('á', 2001));
    }

    #[TestDox('o texto sai aparado; o limite vale depois de aparar')]
    public function testAparar(): void
    {
        self::assertSame('Qual o prazo?', (new PerguntarABlueJusIaInput("  Qual o prazo?\n"))->texto);
        self::assertSame(2000, mb_strlen((new PerguntarABlueJusIaInput('  ' . str_repeat('a', 2000) . '  '))->texto));
    }
}
