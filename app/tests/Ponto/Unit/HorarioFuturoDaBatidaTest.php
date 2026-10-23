<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Service\HorarioFuturoDaBatida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A batida pedida (data e hora no relógio do servidor, a régua da gravação) não pode ficar acima de
 * agora; agora e antes passam.
 */
#[CoversClass(HorarioFuturoDaBatida::class)]
final class HorarioFuturoDaBatidaTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function casos(): iterable
    {
        yield 'um minuto depois de agora' => ['2026-03-10', '12:01', true];
        yield 'a hora de agora' => ['2026-03-10', '12:00', false];
        yield 'um minuto antes de agora' => ['2026-03-10', '11:59', false];
        yield 'amanhã de madrugada' => ['2026-03-11', '00:00', true];
        yield 'ontem à noite' => ['2026-03-09', '23:59', false];
    }

    #[DataProvider('casos')]
    #[TestDox('Agora são 12:00 de 10/03/2026 no servidor: $data $hora no futuro? $esperado')]
    public function testComparaComAgoraNoRelogioDoServidor(string $data, string $hora, bool $esperado): void
    {
        $regra = $this->regra(new \DateTimeImmutable('2026-03-10 12:00:00', new \DateTimeZone(date_default_timezone_get())));

        self::assertSame($esperado, $regra->estaNoFuturo(new \DateTime($data), new \DateTime('1970-01-01 ' . $hora)));
    }

    #[TestDox('O fuso da sede não entra: às 11:30 do servidor, 11:00 já passou, mesmo num escritório em Manaus')]
    public function testFusoDaSedeNaoEntra(): void
    {
        // O relógio injetado chega em Manaus (uma hora atrás de São Paulo); a régua continua a do servidor.
        $agoraEmManaus = (new \DateTimeImmutable('2026-03-10 11:30:00', new \DateTimeZone(date_default_timezone_get())))
            ->setTimezone(new \DateTimeZone('America/Manaus'));
        $regra = $this->regra($agoraEmManaus);

        $hoje = new \DateTime('2026-03-10');
        self::assertFalse($regra->estaNoFuturo($hoje, new \DateTime('1970-01-01 11:00')));
        self::assertTrue($regra->estaNoFuturo($hoje, new \DateTime('1970-01-01 11:31')));
    }

    private function regra(\DateTimeImmutable $agora): HorarioFuturoDaBatida
    {
        return new HorarioFuturoDaBatida(new MockClock($agora));
    }
}
