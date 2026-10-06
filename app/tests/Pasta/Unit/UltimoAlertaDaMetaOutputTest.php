<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\UltimoAlertaDaMetaOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Sino "Alertado: Nome às HH:MM" (desenho `sinoMeta`): o texto do título, a data
 * quando o alerta é de outro dia e a janela de 8 s em que o sino balança.
 */
#[CoversClass(UltimoAlertaDaMetaOutput::class)]
final class UltimoAlertaDaMetaOutputTest extends TestCase
{
    private const AGORA = '2026-10-06 14:40:00';

    #[TestDox('alerta de hoje: "Alertado: Nome às HH:MM. Clique para alertar de novo" — o texto do desenho')]
    public function testAlertaDeHoje(): void
    {
        $a = UltimoAlertaDaMetaOutput::de('Bruno Lima', new \DateTimeImmutable('2026-10-06 09:05:59'), new \DateTimeImmutable(self::AGORA));

        self::assertSame('Bruno Lima', $a->nome);
        self::assertSame('09:05', $a->quando);
        self::assertSame('Alertado: Bruno Lima às 09:05. Clique para alertar de novo', $a->titulo);
        self::assertFalse($a->recente);
    }

    #[TestDox('alerta de outro dia leva a data ("em dd/mm às HH:MM"); de outro ano, com o ano')]
    public function testAlertaDeOutroDia(): void
    {
        $ontem = UltimoAlertaDaMetaOutput::de('Bruno Lima', new \DateTimeImmutable('2026-10-05 23:50'), new \DateTimeImmutable(self::AGORA));
        self::assertSame('05/10 às 23:50', $ontem->quando);
        self::assertSame('Alertado: Bruno Lima em 05/10 às 23:50. Clique para alertar de novo', $ontem->titulo);

        $anoPassado = UltimoAlertaDaMetaOutput::de('Bruno Lima', new \DateTimeImmutable('2025-12-30 08:00'), new \DateTimeImmutable(self::AGORA));
        self::assertSame('30/12/2025 às 08:00', $anoPassado->quando);
        self::assertSame('Alertado: Bruno Lima em 30/12/2025 às 08:00. Clique para alertar de novo', $anoPassado->titulo);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function casosDeRecente(): iterable
    {
        yield 'no mesmo segundo'         => ['2026-10-06 14:40:00', true];
        yield '7 s depois'               => ['2026-10-06 14:39:53', true];
        yield '8 s depois: já parou'     => ['2026-10-06 14:39:52', false];
        yield 'uma hora depois'          => ['2026-10-06 13:40:00', false];
        yield 'relógio adiantado (futuro)' => ['2026-10-06 14:40:05', false];
    }

    #[TestDox('o sino balança só nos 8 s depois do envio: $_dataName')]
    #[DataProvider('casosDeRecente')]
    public function testRecente(string $em, bool $esperado): void
    {
        $a = UltimoAlertaDaMetaOutput::de('Bruno Lima', new \DateTimeImmutable($em), new \DateTimeImmutable(self::AGORA));

        self::assertSame($esperado, $a->recente);
    }

    #[TestDox('destinatário sem nome não vira "Alertado:  às": cai em "o responsável"')]
    public function testSemNome(): void
    {
        $a = UltimoAlertaDaMetaOutput::de('  ', new \DateTimeImmutable('2026-10-06 10:00'), new \DateTimeImmutable(self::AGORA));

        self::assertSame('Alertado: o responsável às 10:00. Clique para alertar de novo', $a->titulo);
        self::assertSame('Alertado: o responsável às 10:00. Clique para alertar de novo', UltimoAlertaDaMetaOutput::de(null, new \DateTimeImmutable('2026-10-06 10:00'), new \DateTimeImmutable(self::AGORA))->titulo);
    }
}
