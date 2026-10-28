<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Tenant\Service\GeradorDeCodigoDaConta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeradorDeCodigoDaConta::class)]
final class GeradorDeCodigoDaContaTest extends TestCase
{
    #[TestDox('o código é BJ-XXXX-XXXX-XXXX (12 caracteres em 3 grupos) no alfabeto sem I, O, 0 e 1, com 17 caracteres no total')]
    public function testFormato(): void
    {
        $gerador = new GeradorDeCodigoDaConta();

        for ($i = 0; $i < 300; ++$i) {
            $codigo = $gerador->gerar();
            self::assertMatchesRegularExpression(GeradorDeCodigoDaConta::PADRAO, $codigo);
            self::assertSame(17, \strlen($codigo));
        }
    }

    #[TestDox('códigos sorteados não se repetem na prática (32^12 combinações)')]
    public function testVariedade(): void
    {
        $gerador = new GeradorDeCodigoDaConta();
        $codigos = [];
        for ($i = 0; $i < 500; ++$i) {
            $codigos[$gerador->gerar()] = true;
        }

        self::assertCount(500, $codigos);
    }
}
