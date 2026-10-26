<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Entity\ConvenioDaUf;
use App\Master\Exception\ConvenioRecusado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConvenioDaUf::class)]
final class ConvenioDaUfTest extends TestCase
{
    private function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-26 10:00:00');
    }

    #[TestDox('guarda a UF em maiúsculas, o valor com centavos e o motivo só em "sem convênio"')]
    public function testGuarda(): void
    {
        $dif = new ConvenioDaUf(' sp ', 'dif', 9.9, $this->agora(), 'ignorado', null, $this->agora());
        $sem = new ConvenioDaUf('RJ', 'sem', 29.9, $this->agora(), '  Não renovado  ', null, $this->agora());

        self::assertSame('SP', $dif->getUf());
        self::assertSame(9.9, $dif->getValor());
        self::assertNull($dif->getMotivo());
        self::assertSame('Não renovado', $sem->getMotivo());
    }

    #[TestDox('definir troca o modo e o valor da linha')]
    public function testDefinir(): void
    {
        $c = new ConvenioDaUf('SP', 'dif', 9.9, $this->agora(), null, null, $this->agora());

        $c->definir('sem', 39.0, $this->agora(), 'Fim', null, $this->agora());

        self::assertSame('sem', $c->getModo());
        self::assertSame(39.0, $c->getValor());
    }

    #[TestDox('UF, modo e valor inválidos são recusados')]
    public function testRecusas(): void
    {
        foreach ([
            fn () => new ConvenioDaUf('XX', 'dif', 1.0, $this->agora(), null, null, $this->agora()),
            fn () => new ConvenioDaUf('SP', 'livre', 1.0, $this->agora(), null, null, $this->agora()),
            fn () => new ConvenioDaUf('SP', 'dif', -0.01, $this->agora(), null, null, $this->agora()),
            fn () => new ConvenioDaUf('SP', 'dif', 1000000.0, $this->agora(), null, null, $this->agora()),
        ] as $acao) {
            try {
                $acao();
                self::fail('Devia recusar');
            } catch (ConvenioRecusado) {
                self::addToAssertionCount(1);
            }
        }
    }
}
