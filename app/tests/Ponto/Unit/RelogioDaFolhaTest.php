<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\RelogioDaFolha;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O instante da emissão e do aceite (spec ponto-desenho-0811, E16, decisão 14): no fuso do servidor e sem fração de
 * segundo, para o texto selado e a coluna (que o DBAL grava sem fuso e sem fração) terem o mesmo valor.
 */
#[Group('ponto')]
#[CoversClass(RelogioDaFolha::class)]
final class RelogioDaFolhaTest extends TestCase
{
    #[TestDox('O relógio em outro fuso e com microssegundos vira o fuso do servidor, no segundo cheio')]
    public function testNormalizaOFusoEAFracao(): void
    {
        $relogio = new RelogioDaFolha(new MockClock(new \DateTimeImmutable('2026-11-03 12:05:07.654321', new \DateTimeZone('UTC'))));

        $agora = $relogio->agora();

        self::assertSame(date_default_timezone_get(), $agora->getTimezone()->getName());
        self::assertSame('000000', $agora->format('u'));
        self::assertSame((new \DateTimeImmutable('2026-11-03 12:05:07', new \DateTimeZone('UTC')))->getTimestamp(), $agora->getTimestamp());
    }
}
