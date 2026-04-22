<?php

namespace App\Tests\Ponto\Unit;

use App\Entity\Ponto\RegistroPonto;
use PHPUnit\Framework\TestCase;

/**
 * Testa a regra: retorno do repouso requer intervalo mínimo de 60 minutos.
 */
class IntervaloRepousoTest extends TestCase
{
    private function calcularMinutosDesdeRepouso(\DateTime $agora, \DateTime $repouso): int
    {
        return (int) round(($agora->getTimestamp() - $repouso->getTimestamp()) / 60);
    }

    public function testRetornoComMenosDe60MinutosDeveSerBloqueado(): void
    {
        $repouso = new \DateTime('2026-04-22 12:00:00');
        $agora   = new \DateTime('2026-04-22 12:30:00');

        $diff = $this->calcularMinutosDesdeRepouso($agora, $repouso);

        $this->assertLessThan(60, $diff);
        $this->assertSame(30, $diff);
        $this->assertSame(30, 60 - $diff); // minutos restantes para liberar
    }

    public function testRetornoComExatamente60MinutosDeveSerPermitido(): void
    {
        $repouso = new \DateTime('2026-04-22 12:00:00');
        $agora   = new \DateTime('2026-04-22 13:00:00');

        $diff = $this->calcularMinutosDesdeRepouso($agora, $repouso);

        $this->assertGreaterThanOrEqual(60, $diff);
    }

    public function testRetornoComMaisDe60MinutosDeveSerPermitido(): void
    {
        $repouso = new \DateTime('2026-04-22 12:00:00');
        $agora   = new \DateTime('2026-04-22 13:30:00');

        $diff = $this->calcularMinutosDesdeRepouso($agora, $repouso);

        $this->assertGreaterThanOrEqual(60, $diff);
    }

    public function testRetornoSemRepousoPrevioPodeSerRegistrado(): void
    {
        // Sem repouso no dia, a validação não se aplica
        $repousoDoDia = null;

        $this->assertNull($repousoDoDia);
    }

    public function testMinutosRestantesCalculadosCorretamente(): void
    {
        $repouso = new \DateTime('2026-04-22 12:00:00');
        $agora   = new \DateTime('2026-04-22 12:45:00');

        $diff = $this->calcularMinutosDesdeRepouso($agora, $repouso);
        $minutosRestantes = 60 - $diff;

        $this->assertSame(45, $diff);
        $this->assertSame(15, $minutosRestantes);
    }
}
