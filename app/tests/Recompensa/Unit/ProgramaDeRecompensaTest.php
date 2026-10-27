<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Recompensa\Entity\ProgramaDeRecompensa;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Shared\Contract\Auditavel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProgramaDeRecompensa::class)]
final class ProgramaDeRecompensaTest extends TestCase
{
    #[TestDox('nasce com os padrões do desenho: ativo, R$ 10,00, libera com 10, carência de 30 dias, alerta em 5, exige pasta')]
    public function testPadroes(): void
    {
        $p = new ProgramaDeRecompensa();

        self::assertInstanceOf(Auditavel::class, $p);
        self::assertSame(1, $p->getId());
        self::assertTrue($p->isAtivo());
        self::assertSame(1000, $p->getValorCentavos());
        self::assertSame(10, $p->getMinEscritorios());
        self::assertSame(30, $p->getCarenciaDias());
        self::assertSame(5, $p->getAlertaQtd());
        self::assertTrue($p->isExigePasta());
    }

    #[TestDox('alterarRegras grava as seis regras de uma vez')]
    public function testAlteraRegras(): void
    {
        $p = new ProgramaDeRecompensa();

        $p->alterarRegras(false, 2550, 3, 0, 8, false);

        self::assertFalse($p->isAtivo());
        self::assertSame(2550, $p->getValorCentavos());
        self::assertSame(3, $p->getMinEscritorios());
        self::assertSame(0, $p->getCarenciaDias());
        self::assertSame(8, $p->getAlertaQtd());
        self::assertFalse($p->isExigePasta());
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function invalidos(): iterable
    {
        yield 'valor negativo' => [-1, 10, 30, 5];
        yield 'valor acima do teto' => [10000001, 10, 30, 5];
        yield 'libera com zero' => [1000, 0, 30, 5];
        yield 'carência negativa' => [1000, 10, -1, 5];
        yield 'carência absurda' => [1000, 10, 3651, 5];
        yield 'alerta zero' => [1000, 10, 30, 0];
    }

    #[DataProvider('invalidos')]
    #[TestDox('recusa regra fora do intervalo e não muda nada')]
    public function testRecusa(int $valor, int $min, int $carencia, int $alerta): void
    {
        $p = new ProgramaDeRecompensa();

        try {
            $p->alterarRegras(false, $valor, $min, $carencia, $alerta, false);
            self::fail('devia recusar');
        } catch (RecompensaRecusada) {
            self::assertTrue($p->isAtivo());
            self::assertSame(1000, $p->getValorCentavos());
            self::assertTrue($p->isExigePasta());
        }
    }

    #[TestDox('valor zero é aceito (programa sem recompensa em dinheiro)')]
    public function testValorZero(): void
    {
        $p = new ProgramaDeRecompensa();
        $p->alterarRegras(true, 0, 1, 0, 1, true);

        self::assertSame(0, $p->getValorCentavos());
    }
}
