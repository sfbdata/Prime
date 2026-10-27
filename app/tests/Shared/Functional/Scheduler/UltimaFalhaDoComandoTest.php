<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Scheduler;

use App\Shared\Scheduler\AgendaDeTarefas;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A consulta do limite de e-mails do aviso de falha, no banco de verdade. */
#[CoversClass(FalhaAgendadaRepository::class)]
final class UltimaFalhaDoComandoTest extends KernelTestCase
{
    #[TestDox('Devolve a falha mais recente do mesmo comando, na hora de Brasília em que foi gravada; sem falha, null')]
    public function testUltimaDoComando(): void
    {
        $falhas = static::getContainer()->get(FalhaAgendadaRepository::class);
        $fuso   = new \DateTimeZone(AgendaDeTarefas::FUSO);
        $comando = 'app:teste:ultima-' . uniqid();

        self::assertNull($falhas->ultimaDoComando($comando));

        $falhas->registrar($comando, new \DateTimeImmutable('2026-10-23 09:00:00', $fuso), \RuntimeException::class, null);
        $falhas->registrar($comando, new \DateTimeImmutable('2026-10-23 09:40:00', $fuso), \RuntimeException::class, 1);
        $falhas->registrar('outro:' . $comando, new \DateTimeImmutable('2026-10-23 11:00:00', $fuso), \RuntimeException::class, 1);

        $ultima = $falhas->ultimaDoComando($comando);
        self::assertNotNull($ultima);
        self::assertSame('2026-10-23T09:40:00-03:00', $ultima->format(\DATE_ATOM));
    }
}
