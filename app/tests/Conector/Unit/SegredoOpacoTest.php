<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Service\SegredoOpaco;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SegredoOpaco::class)]
final class SegredoOpacoTest extends TestCase
{
    #[TestDox('Gera 256 bits em base64url sem "=" (43 caracteres) e devolve o SHA-256 hexadecimal do valor')]
    public function testGerar(): void
    {
        $segredo = (new SegredoOpaco())->gerar();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $segredo['valor']);
        self::assertSame(hash('sha256', $segredo['valor']), $segredo['hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $segredo['hash']);
    }

    #[TestDox('Cada segredo é novo: dois seguidos nunca se repetem')]
    public function testCadaUmEhNovo(): void
    {
        $sut = new SegredoOpaco();
        $valores = array_map(static fn (): string => $sut->gerar()['valor'], range(1, 50));

        self::assertCount(50, array_unique($valores));
    }

    #[TestDox('hashDe é o mesmo hash que o gerar grava: o app apresenta o valor e a busca acha a linha')]
    public function testHashDe(): void
    {
        $sut = new SegredoOpaco();
        $segredo = $sut->gerar();

        self::assertSame($segredo['hash'], $sut->hashDe($segredo['valor']));
        self::assertNotSame($segredo['hash'], $sut->hashDe($segredo['valor'] . 'x'));
    }
}
