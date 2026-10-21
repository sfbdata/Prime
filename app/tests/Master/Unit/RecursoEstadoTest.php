<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Entity\RecursoEstado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Estado de uma tela ou função (spec perfis-1.2-telas-e-recursos, R2 e R4).
 */
#[CoversClass(RecursoEstado::class)]
final class RecursoEstadoTest extends TestCase
{
    #[TestDox('nasce ativo')]
    public function testNasceAtivo(): void
    {
        self::assertTrue((new RecursoEstado('dashboard'))->estaAtivoEm(new \DateTimeImmutable('2026-10-19')));
    }

    #[TestDox('desligado com data vale até as 23:59:59 do dia e volta a ativo no dia seguinte (R4)')]
    public function testDataVencidaReativa(): void
    {
        $estado = new RecursoEstado('dashboard');
        $estado->desativar('Revisão ética', new \DateTimeImmutable('2026-10-20 15:00'), null, new \DateTimeImmutable('2026-10-19 10:00'));

        self::assertFalse($estado->estaAtivoEm(new \DateTimeImmutable('2026-10-19 10:00')));
        self::assertFalse($estado->estaAtivoEm(new \DateTimeImmutable('2026-10-20 23:59:59')));
        self::assertTrue($estado->estaAtivoEm(new \DateTimeImmutable('2026-10-21 00:00:00')));
        self::assertFalse($estado->isAtivo(), 'o gravado não muda: a leitura é que compara com hoje');
    }

    #[TestDox('desligado sem data fica desligado até alguém religar')]
    public function testSemDataNaoVolta(): void
    {
        $estado = new RecursoEstado('dashboard');
        $estado->desativar(null, null, null, new \DateTimeImmutable('2026-10-19'));

        self::assertFalse($estado->estaAtivoEm(new \DateTimeImmutable('2030-01-01')));
    }

    #[TestDox('religar limpa motivo e data')]
    public function testReativarLimpa(): void
    {
        $estado = new RecursoEstado('dashboard');
        $estado->desativar('Correção de falha', new \DateTimeImmutable('2026-11-01'), null, new \DateTimeImmutable('2026-10-19'));
        $estado->reativar(null, new \DateTimeImmutable('2026-10-20 08:00'));

        self::assertTrue($estado->isAtivo());
        self::assertNull($estado->getMotivo());
        self::assertNull($estado->getAte());
        self::assertSame('2026-10-20 08:00', $estado->getAlteradoEm()->format('Y-m-d H:i'));
    }
}
