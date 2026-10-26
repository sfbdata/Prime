<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Shared\Contract\Auditavel;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(CondicaoDeRecompensa::class)]
final class CondicaoDeRecompensaTest extends TestCase
{
    #[TestDox('nasce sem campo próprio (tudo na regra geral) e sem efeito')]
    public function testNasceVazia(): void
    {
        $c = new CondicaoDeRecompensa(Pessoas::usuario(5));

        self::assertInstanceOf(Auditavel::class, $c);
        self::assertSame(5, $c->getUserId());
        self::assertNull($c->getValorCentavos());
        self::assertNull($c->getMinEscritorios());
        self::assertNull($c->getCarenciaDias());
        self::assertFalse($c->isRecorrente());
        self::assertTrue($c->semEfeito());
    }

    #[TestDox('definir grava os campos próprios; nulo volta o campo para a regra geral')]
    public function testDefine(): void
    {
        $c = new CondicaoDeRecompensa(Pessoas::usuario(5));

        $c->definir(2500, 3, null, false, null, '  Convênio com a seccional  ');

        self::assertSame(2500, $c->getValorCentavos());
        self::assertSame(3, $c->getMinEscritorios());
        self::assertNull($c->getCarenciaDias());
        self::assertSame('Convênio com a seccional', $c->getMotivo());
        self::assertFalse($c->semEfeito());

        $c->definir(null, null, null, false, null, '');

        self::assertTrue($c->semEfeito());
        self::assertNull($c->getMotivo());
    }

    #[TestDox('recorrente exige o valor mensal e o guarda; sem recorrência o mensal é descartado')]
    public function testRecorrente(): void
    {
        $c = new CondicaoDeRecompensa(Pessoas::usuario(5));

        $c->definir(null, null, null, true, 200, null);
        self::assertTrue($c->isRecorrente());
        self::assertSame(200, $c->getMensalCentavos());
        self::assertFalse($c->semEfeito());

        $c->definir(null, null, null, false, 200, null);
        self::assertNull($c->getMensalCentavos());
    }

    #[TestDox('recusa valor negativo, "libera com" zero, carência negativa, recorrente sem mensal e motivo longo demais')]
    public function testRecusa(): void
    {
        $casos = [
            [-1, null, null, false, null, null],
            [null, 0, null, false, null, null],
            [null, null, -1, false, null, null],
            [null, null, null, true, null, null],
            [null, null, null, true, 0, null],
            [null, null, null, false, null, str_repeat('a', 256)],
        ];
        foreach ($casos as $i => $caso) {
            $c = new CondicaoDeRecompensa(Pessoas::usuario(5));
            try {
                $c->definir(...$caso);
                self::fail('devia recusar o caso ' . $i);
            } catch (RecompensaRecusada) {
                self::assertTrue($c->semEfeito(), 'caso ' . $i);
            }
        }
    }
}
