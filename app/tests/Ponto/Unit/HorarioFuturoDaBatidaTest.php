<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Service\FusoDoEscritorio;
use App\Ponto\Service\HorarioFuturoDaBatida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A batida pedida (data e hora no fuso do escritório) não pode ficar acima de agora; agora e antes passam.
 */
#[CoversClass(HorarioFuturoDaBatida::class)]
#[CoversClass(FusoDoEscritorio::class)]
final class HorarioFuturoDaBatidaTest extends TestCase
{
    /** Meio-dia de 10/03/2026 em São Paulo (15:00 UTC). */
    private const AGORA = '2026-03-10 15:00:00';

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
    #[TestDox('Sem sede vale São Paulo: $data $hora no futuro? $esperado')]
    public function testComparaComAgoraNoFusoPadrao(string $data, string $hora, bool $esperado): void
    {
        self::assertSame($esperado, $this->regra()->estaNoFuturo(new \DateTime($data), new \DateTime('1970-01-01 ' . $hora), new Tenant()));
    }

    #[TestDox('Com sede em outro fuso, a hora é lida no fuso da sede')]
    public function testUsaOFusoDaSede(): void
    {
        $tenant = new Tenant();
        $sede = new Sede();
        $sede->setTimezone('America/Manaus');   // uma hora atrás de São Paulo: agora são 11:00 lá
        $tenant->addSede($sede);

        self::assertTrue($this->regra()->estaNoFuturo(new \DateTime('2026-03-10'), new \DateTime('1970-01-01 11:30'), $tenant));
        self::assertFalse($this->regra()->estaNoFuturo(new \DateTime('2026-03-10'), new \DateTime('1970-01-01 11:00'), $tenant));
    }

    private function regra(): HorarioFuturoDaBatida
    {
        return new HorarioFuturoDaBatida(new MockClock(new \DateTimeImmutable(self::AGORA, new \DateTimeZone('UTC'))));
    }
}
