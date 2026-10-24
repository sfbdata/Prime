<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Zeramento;

use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Zeramento\CortesDoDashboard;
use App\Dashboard\Zeramento\RecontagemComCorte;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O cálculo com corte (DASH-014): o início efetivo de cada contagem é max(início do período, corte).
 * O período fica com a consulta (que recebe `data_de` e `contar_desde` juntos); aqui se prova que só
 * quem tem corte é recontado, a partir do SEU corte.
 */
#[CoversClass(RecontagemComCorte::class)]
final class RecontagemComCorteTest extends TestCase
{
    private function pessoa(int $id): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn($id);

        return $u;
    }

    #[TestDox('Sem corte na coluna, o mapa sai igual e a consulta não roda de novo')]
    public function testSemCorte(): void
    {
        $chamadas = 0;
        $mapa = RecontagemComCorte::aplicar([7 => 5, 8 => 3], CortesDoDashboard::nenhum(), 'metas', [7, 8], function () use (&$chamadas): array {
            ++$chamadas;

            return [];
        });

        self::assertSame([7 => 5, 8 => 3], $mapa);
        self::assertSame(0, $chamadas);
    }

    #[TestDox('Com corte de uma pessoa, só ela é recontada a partir do corte; sem nada depois do corte, sai do mapa (zero)')]
    public function testCorteDeUmaPessoa(): void
    {
        $cortes = CortesDoDashboard::deZeramentos([
            new DashboardZeramento(new Tenant(), 'metas', $this->pessoa(7), new \DateTimeImmutable('2026-10-15 14:30'), new User()),
            new DashboardZeramento(new Tenant(), 'metas', $this->pessoa(9), new \DateTimeImmutable('2026-10-20 08:00'), new User()),
        ]);
        $desdes = [];

        $mapa = RecontagemComCorte::aplicar([7 => 5, 8 => 3, 9 => 4], $cortes, 'metas', [7, 8, 9], function (\DateTimeImmutable $desde) use (&$desdes): array {
            $desdes[] = $desde->format('Y-m-d H:i');

            return $desde->format('d') === '15' ? [7 => 1, 8 => 2] : [];
        });

        self::assertSame([8 => 3, 7 => 1], $mapa);
        self::assertSame(['2026-10-15 14:30', '2026-10-20 08:00'], $desdes);
    }
}
