<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Enum\TipoDeCompromisso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TipoDeCompromisso::class)]
final class TipoDeCompromissoTest extends TestCase
{
    #[TestDox('são seis tipos, um por legenda do desenho 1A, e os valores cabem na coluna (varchar 10)')]
    public function testSeisTipos(): void
    {
        $valores = array_map(static fn (TipoDeCompromisso $t): string => $t->value, TipoDeCompromisso::cases());

        self::assertSame(['meus', 'aud', 'reun', 'prazo', 'urg', 'pess', 'lemb'], $valores);
        foreach ($valores as $v) {
            self::assertLessThanOrEqual(10, strlen($v));
        }
    }

    #[TestDox('as cores dos três tipos novos são as das legendas Urgente, Pessoal e Lembrete do desenho')]
    public function testCoresDosNovos(): void
    {
        self::assertSame('#f56954', TipoDeCompromisso::Urgente->cor());
        self::assertSame('#605ca8', TipoDeCompromisso::Pessoal->cor());
        self::assertSame('#00c0ef', TipoDeCompromisso::Lembrete->cor());
        self::assertSame(15, TipoDeCompromisso::Lembrete->lembretePadrao());
    }
}
