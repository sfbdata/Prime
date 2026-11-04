<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Recompensa\Service\SaldoComValidade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaldoComValidade::class)]
final class SaldoComValidadeTest extends TestCase
{
    private const DEPLOY = '2026-11-04 12:00:00';

    /** @return array{valor: int, em: \DateTimeImmutable} */
    private static function m(int $valor, string $em): array
    {
        return ['valor' => $valor, 'em' => new \DateTimeImmutable($em)];
    }

    /**
     * @param list<array{valor: int, em: \DateTimeImmutable}> $creditos
     * @param list<array{valor: int, em: \DateTimeImmutable}> $estornos
     * @param list<array{valor: int, em: \DateTimeImmutable}> $pagos
     *
     * @return array{saldo: int, expirado: int}
     */
    private static function apurar(array $creditos, array $estornos, array $pagos, string $agora, ?string $desde = self::DEPLOY): array
    {
        return SaldoComValidade::apurar($creditos, $estornos, $pagos, $desde === null ? null : new \DateTimeImmutable($desde), new \DateTimeImmutable($agora));
    }

    #[TestDox('sem validadeDesde nada vence e o saldo é o de antes: max(0, max(0, créditos - estornos) - pagos), sem olhar a ordem')]
    public function testSemValidadeIgualAoAntigo(): void
    {
        $creditos = [self::m(1000, '2020-01-01'), self::m(500, '2021-01-01')];

        self::assertSame(['saldo' => 1200, 'expirado' => 0], self::apurar($creditos, [], [self::m(300, '2019-01-01')], '2030-01-01', null));
        self::assertSame(['saldo' => 0, 'expirado' => 0], self::apurar($creditos, [self::m(9999, '2025-01-01')], [], '2030-01-01', null));
        self::assertSame(['saldo' => 0, 'expirado' => 0], self::apurar($creditos, [self::m(1000, '2025-01-01')], [self::m(800, '2025-01-01')], '2030-01-01', null), 'o pago maior que o resto zera');
    }

    #[TestDox('crédito ganho antes do deploy vence 3 meses depois do deploy, não da data em que foi ganho')]
    public function testCreditoAntigoVenceTresMesesAposODeploy(): void
    {
        $creditos = [self::m(1000, '2025-01-01')];

        self::assertSame(['saldo' => 1000, 'expirado' => 0], self::apurar($creditos, [], [], '2027-02-04 11:59:59'));
        self::assertSame(['saldo' => 0, 'expirado' => 1000], self::apurar($creditos, [], [], '2027-02-04 12:00:00'), 'vence no instante exato');
    }

    #[TestDox('crédito ganho depois do deploy vence 3 meses depois do próprio ganho')]
    public function testCreditoNovoVenceTresMesesAposOGanho(): void
    {
        $creditos = [self::m(1000, '2026-12-10 08:00:00'), self::m(700, '2027-01-20 08:00:00')];

        self::assertSame(['saldo' => 1700, 'expirado' => 0], self::apurar($creditos, [], [], '2027-03-09'));
        self::assertSame(['saldo' => 700, 'expirado' => 1000], self::apurar($creditos, [], [], '2027-03-11'));
        self::assertSame(['saldo' => 0, 'expirado' => 1700], self::apurar($creditos, [], [], '2027-04-21'));
    }

    #[TestDox('pagamento consome primeiro o crédito que vence primeiro, mesmo lançado depois na lista')]
    public function testPagamentoConsomeOMaisAntigo(): void
    {
        $creditos = [self::m(700, '2027-01-20'), self::m(1000, '2026-12-10')];

        $r = self::apurar($creditos, [], [self::m(600, '2027-02-01')], '2027-03-15');

        self::assertSame(['saldo' => 700, 'expirado' => 400], $r, 'os R$ 6,00 saíram do de dezembro; sobram R$ 4,00 dele, vencidos');
    }

    #[TestDox('pagamento anterior ao deploy entra no deploy: consome antes de qualquer vencimento e nunca volta a ser saldo')]
    public function testPagamentoAntesDoDeploy(): void
    {
        $r = self::apurar([self::m(1000, '2025-01-01'), self::m(500, '2026-12-01')], [], [self::m(1000, '2025-06-01')], '2027-02-10');

        self::assertSame(['saldo' => 500, 'expirado' => 0], $r);
    }

    #[TestDox('estorno consome como débito, também do que vence primeiro; estorno anterior ao deploy entra no deploy')]
    public function testEstorno(): void
    {
        $creditos = [self::m(1000, '2025-01-01'), self::m(1000, '2026-12-01')];

        self::assertSame(['saldo' => 1000, 'expirado' => 0], self::apurar($creditos, [self::m(1000, '2025-02-01')], [], '2027-02-10'));
        self::assertSame(['saldo' => 1000, 'expirado' => 600], self::apurar($creditos, [self::m(400, '2026-12-15')], [], '2027-02-10'));
    }

    #[TestDox('débito depois do vencimento não consome o crédito vencido: usa o válido ou se perde')]
    public function testDebitoDepoisDoVencimentoNaoConsomeVencido(): void
    {
        $creditos = [self::m(1000, '2026-12-01'), self::m(500, '2027-02-15')];

        self::assertSame(['saldo' => 200, 'expirado' => 1000], self::apurar($creditos, [], [self::m(300, '2027-03-10')], '2027-03-20'));
        self::assertSame(['saldo' => 0, 'expirado' => 1000], self::apurar([self::m(1000, '2026-12-01')], [], [self::m(300, '2027-03-10')], '2027-03-20'), 'sem válido, o débito se perde');
    }

    #[TestDox('nunca negativo: débito maior que todo o crédito zera; débito antes do crédito existir se perde')]
    public function testNuncaNegativo(): void
    {
        self::assertSame(['saldo' => 0, 'expirado' => 0], self::apurar([self::m(1000, '2026-12-01')], [self::m(99999, '2026-12-02')], [], '2027-01-01'));
        self::assertSame(['saldo' => 1000, 'expirado' => 0], self::apurar([self::m(1000, '2026-12-10')], [self::m(500, '2026-12-01')], [], '2027-01-01'));
    }

    #[TestDox('no mesmo instante o crédito entra antes do débito')]
    public function testMesmoInstante(): void
    {
        self::assertSame(['saldo' => 600, 'expirado' => 0], self::apurar([self::m(1000, '2026-12-01 10:00')], [], [self::m(400, '2026-12-01 10:00')], '2027-01-01'));
    }
}
