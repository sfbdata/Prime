<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\Service\TituloAutomaticoDoChat;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TituloAutomaticoDoChat::class)]
final class TituloAutomaticoDoChatTest extends TestCase
{
    #[TestDox('Usa o título do provedor (limpo) e pede só 24 tokens')]
    public function testUsaOTituloDoProvedor(): void
    {
        $falso = new ProvedorFalso();
        $falso->responderCom("\"Prazo da apelação\"\n");

        $titulo = (new TituloAutomaticoDoChat($falso))->gerar('Qual o prazo?', 'Quinze dias.');

        self::assertSame('Prazo da apelação', $titulo);
        self::assertSame(24, $falso->ultimoPedido()?->maxTokens);
    }

    #[TestDox('Se o provedor falha, usa o início da pergunta (60 caracteres no máximo)')]
    public function testFallback(): void
    {
        $falso = new ProvedorFalso();
        $falso->falharTransitoriamente();

        $titulo = (new TituloAutomaticoDoChat($falso))->gerar(str_repeat('palavra ', 30), 'resposta');

        self::assertLessThanOrEqual(60, mb_strlen($titulo));
        self::assertStringEndsWith('...', $titulo);
    }
}
