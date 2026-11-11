<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnidadeDaAssembleia::class)]
final class CodigoDaUnidadeTest extends TestCase
{
    public function testCodigoValeDezMinutosESoPodeSerUsadoUmaVez(): void
    {
        $u = $this->unidade();
        $agora = new \DateTimeImmutable('2026-11-11 10:00:00');
        self::assertTrue($u->emitirCodigo('hash', 'navegador', $agora));
        self::assertTrue($u->confirmarCodigo('hash', 'navegador', $agora->modify('+9 minutes')));
        self::assertFalse($u->confirmarCodigo('hash', 'navegador', $agora->modify('+9 minutes')));
    }

    public function testExpiracaoExataRecusa(): void
    {
        $u = $this->unidade();
        $agora = new \DateTimeImmutable('2026-11-11 10:00:00');
        $u->emitirCodigo('hash', 'navegador', $agora);
        self::assertFalse($u->confirmarCodigo('hash', 'navegador', $agora->modify('+10 minutes')));
    }

    public function testCincoErrosBloqueiamMesmoSeDepoisAcertar(): void
    {
        $u = $this->unidade();
        $agora = new \DateTimeImmutable();
        $u->emitirCodigo('hash', 'navegador', $agora);
        for ($i = 0; $i < 5; ++$i) {
            self::assertFalse($u->confirmarCodigo('errado', 'navegador', $agora));
        }
        self::assertFalse($u->confirmarCodigo('hash', 'navegador', $agora));
    }

    public function testCodigoNaoFuncionaEmOutroNavegador(): void
    {
        $u = $this->unidade();
        $agora = new \DateTimeImmutable();
        $u->emitirCodigo('hash', 'navegador', $agora);
        self::assertFalse($u->confirmarCodigo('hash', 'outro', $agora));
        self::assertTrue($u->confirmarCodigo('hash', 'navegador', $agora));
    }

    public function testCincoPedidosPorHoraEReenvioInvalidaCodigoAnterior(): void
    {
        $u = $this->unidade();
        $agora = new \DateTimeImmutable();
        self::assertTrue($u->emitirCodigo('velho', 'navegador', $agora));
        for ($i = 0; $i < 4; ++$i) {
            self::assertTrue($u->emitirCodigo('novo', 'navegador', $agora));
        }
        self::assertFalse($u->emitirCodigo('sexto', 'navegador', $agora));
        self::assertFalse($u->confirmarCodigo('velho', 'navegador', $agora));
        self::assertTrue($u->confirmarCodigo('novo', 'navegador', $agora));
        self::assertTrue($u->emitirCodigo('depois', 'navegador', $agora->modify('+1 hour')));
    }

    private function unidade(): UnidadeDaAssembleia
    {
        return new UnidadeDaAssembleia(new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora'), '101', str_repeat('a', 32), str_repeat('b', 64));
    }
}
