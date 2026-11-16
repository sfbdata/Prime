<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\ConsentimentoDoConector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsentimentoDoConector::class)]
final class ConsentimentoDoConectorTest extends TestCase
{
    use CenarioDoConector;

    #[TestDox('Nasce ativo; revogar grava a data uma vez só e não apaga')]
    public function testRevogar(): void
    {
        $consentimento = self::consentimento();
        self::assertTrue($consentimento->isAtivo());

        $consentimento->revogar(self::em('2026-11-16 12:00:00'));
        $consentimento->revogar(self::em('2026-11-16 13:00:00'));

        self::assertFalse($consentimento->isAtivo());
        self::assertEquals(self::em('2026-11-16 12:00:00'), $consentimento->getRevogadoEm());
        self::assertSame(['mcp:leitura', 'offline_access'], $consentimento->getEscopos(), 'o histórico fica');
    }

    #[TestDox('Registrar uso grava o último uso no máximo uma vez por minuto (evita escrita a cada chamada)')]
    public function testRegistrarUso(): void
    {
        $consentimento = self::consentimento();
        $consentimento->registrarUso(self::em('2026-11-16 12:00:00'));
        $consentimento->registrarUso(self::em('2026-11-16 12:00:59'));
        self::assertEquals(self::em('2026-11-16 12:00:00'), $consentimento->getUltimoUsoEm());

        $consentimento->registrarUso(self::em('2026-11-16 12:01:00'));
        self::assertEquals(self::em('2026-11-16 12:01:00'), $consentimento->getUltimoUsoEm());
    }
}
