<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Entity\DashboardMetaPastas;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Meta de pastas (DASH-016): o mês de vigência é sempre o dia 1º; valor negativo é recusado. */
#[CoversClass(DashboardMetaPastas::class)]
final class DashboardMetaPastasTest extends TestCase
{
    #[TestDox('O mês de vigência vira o dia 1º, à meia-noite')]
    public function testMesNormalizado(): void
    {
        $meta = new DashboardMetaPastas(new Tenant(), new \DateTimeImmutable('2026-10-23 15:40'), 30, new User());

        self::assertSame('2026-10-01 00:00:00', $meta->getMes()->format('Y-m-d H:i:s'));
        self::assertSame(30, $meta->getValor());
    }

    #[TestDox('Meta negativa é recusada')]
    public function testValorNegativo(): void
    {
        $this->expectException(\DomainException::class);

        new DashboardMetaPastas(new Tenant(), new \DateTimeImmutable('2026-10-01'), -1, new User());
    }
}
