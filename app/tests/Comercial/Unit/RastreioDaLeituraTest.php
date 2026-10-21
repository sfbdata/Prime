<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Service\RastreioDaLeitura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(RastreioDaLeitura::class)]
final class RastreioDaLeituraTest extends TestCase
{
    #[TestDox('só rastreia com a escolha da campanha E as duas chaves globais (a configuração e a Política)')]
    public function testEfetivo(): void
    {
        self::assertTrue((new RastreioDaLeitura(true, true))->efetivo(true));
        self::assertFalse((new RastreioDaLeitura(true, true))->efetivo(false));
        self::assertFalse((new RastreioDaLeitura(false, true))->efetivo(true));
        self::assertFalse((new RastreioDaLeitura(true, false))->efetivo(true));
        self::assertFalse((new RastreioDaLeitura(true, false))->disponivel());
        self::assertTrue((new RastreioDaLeitura(true, true))->disponivel());
    }

    #[TestDox('hoje, com a Política sem citar o rastreio, não está disponível nem com a configuração ligada')]
    public function testHojeDesligado(): void
    {
        self::assertFalse((new RastreioDaLeitura(true))->disponivel());
    }
}
