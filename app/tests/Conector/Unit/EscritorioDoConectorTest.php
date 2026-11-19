<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\VersaoDoAceiteDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(EscritorioDoConector::class)]
#[CoversClass(VersaoDoAceiteDoConector::class)]
final class EscritorioDoConectorTest extends TestCase
{
    use CenarioDoConector;

    #[TestDox('Nasce desligado e sem aceite')]
    public function testNasceFechado(): void
    {
        $escritorio = new EscritorioDoConector(new Tenant());

        self::assertFalse($escritorio->isLigado());
        self::assertNull($escritorio->getAceiteEm());
        self::assertFalse($escritorio->ligadoComAceite());
    }

    #[TestDox('Ligar grava o aceite da versão vigente com quem ligou e já abre o conector, sem liberação do Master')]
    public function testLigarGravaAceiteEAbre(): void
    {
        $escritorio = new EscritorioDoConector(new Tenant());
        $admin = new User();

        $escritorio->ligar($admin, self::em('2026-11-16 10:00:00'));

        self::assertSame(1, $escritorio->getAceiteVersao());
        self::assertSame(VersaoDoAceiteDoConector::atual()->value, $escritorio->getAceiteVersao());
        self::assertSame($admin, $escritorio->getAceitoPor());
        self::assertEquals(self::em('2026-11-16 10:00:00'), $escritorio->getAceiteEm());
        self::assertTrue($escritorio->ligadoComAceite());
    }

    #[TestDox('Religar com o aceite vigente não troca a data nem quem aceitou')]
    public function testReligarMantemAceite(): void
    {
        $escritorio = new EscritorioDoConector(new Tenant());
        $primeiro = new User();
        $escritorio->ligar($primeiro, self::em('2026-11-16 10:00:00'));
        $escritorio->desligar();

        $escritorio->ligar(new User(), self::em('2026-11-17 10:00:00'));

        self::assertSame($primeiro, $escritorio->getAceitoPor());
        self::assertEquals(self::em('2026-11-16 10:00:00'), $escritorio->getAceiteEm());
    }

    #[TestDox('Desligar fecha o conector e guarda o aceite como histórico')]
    public function testDesligarGuardaAceite(): void
    {
        $escritorio = new EscritorioDoConector(new Tenant());
        $escritorio->ligar(new User(), self::em('2026-11-16 10:00:00'));

        $escritorio->desligar();
        self::assertFalse($escritorio->ligadoComAceite());

        self::assertNotNull($escritorio->getAceiteEm());
    }
}
