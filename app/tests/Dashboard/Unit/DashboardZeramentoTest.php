<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Entity\DashboardZeramento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Invariantes do zeramento de coluna (DASH-014): nasce ativo, desfaz uma vez só. */
#[CoversClass(DashboardZeramento::class)]
final class DashboardZeramentoTest extends TestCase
{
    private function zeramento(?User $usuario = null): DashboardZeramento
    {
        return new DashboardZeramento(new Tenant(), 'relatorio', $usuario, new \DateTimeImmutable('2026-10-23 10:00'), (new User())->setEmail('master@test.com'));
    }

    #[TestDox('Nasce ativo; sem pessoa vale para todos, com pessoa só para ela')]
    public function testNasceAtivo(): void
    {
        self::assertTrue($this->zeramento()->estaAtivo());
        self::assertTrue($this->zeramento()->valeParaTodos());
        self::assertFalse($this->zeramento((new User())->setEmail('ana@test.com'))->valeParaTodos());
    }

    #[TestDox('Desfazer guarda quem e quando; desfazer de novo é recusado e não apaga o primeiro')]
    public function testDesfazerUmaVez(): void
    {
        $z     = $this->zeramento();
        $quem  = (new User())->setEmail('master@test.com');
        $quando = new \DateTimeImmutable('2026-10-24 09:00');

        $z->desfazer($quem, $quando);

        self::assertFalse($z->estaAtivo());
        self::assertSame($quem, $z->getDesfeitoPor());
        self::assertSame($quando, $z->getDesfeitoEm());

        $this->expectException(\LogicException::class);
        $z->desfazer((new User())->setEmail('outro@test.com'), new \DateTimeImmutable());
    }
}
