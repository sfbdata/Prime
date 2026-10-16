<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\FaixaEtaria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(FaixaEtaria::class)]
final class FaixaEtariaTest extends TestCase
{
    #[TestDox('Distribui pelas 5 faixas do desenho, conta à parte quem não tem data e calcula a média real')]
    public function testDistribui(): void
    {
        $hoje = new \DateTimeImmutable('2026-10-16');

        $r = FaixaEtaria::distribuir([
            '1986-08-25', // 40
            '2000-10-17', // 25 (faz 26 amanhã)
            '2000-10-16', // 26 hoje
            '1971-01-01', // 55
            '2010-01-01', // 16: abaixo das faixas, entra na média e em nenhuma faixa
            null,
            '',
        ], $hoje);

        self::assertSame([
            ['rot' => '18 a 24', 'n' => 0],
            ['rot' => '25 a 34', 'n' => 2],
            ['rot' => '35 a 44', 'n' => 1],
            ['rot' => '45 a 54', 'n' => 0],
            ['rot' => '55 ou +', 'n' => 1],
        ], $r['faixas']);
        self::assertSame(2, $r['semData']);
        self::assertSame((int) round((40 + 25 + 26 + 55 + 16) / 5), $r['media']);
    }

    #[TestDox('Sem nenhuma data, a média é nula (a tela mostra "sem informação")')]
    public function testSemDatas(): void
    {
        $r = FaixaEtaria::distribuir([null], new \DateTimeImmutable('2026-10-16'));

        self::assertNull($r['media']);
        self::assertSame(1, $r['semData']);
    }
}
