<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Service\PermissoesDaAgenda;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissoesDaAgenda::class)]
final class PermissoesDaAgendaTest extends TestCase
{
    #[TestDox('Ver prazos segue a permissão "Ver prazos na agenda"; ver pastas segue o módulo Pastas; uma não traz a outra')]
    public function testRegrasSeparadas(): void
    {
        $soPrazos = $this->createStub(PermissionChecker::class);
        $soPrazos->method('hasPermission')->willReturnCallback(static fn (User $u, ?Tenant $t, string $c): bool => 'resources.agenda.ver_prazos' === $c);
        $soPrazos->method('canAccessModule')->willReturn(false);
        $soPastas = $this->createStub(PermissionChecker::class);
        $soPastas->method('hasPermission')->willReturn(false);
        $soPastas->method('canAccessModule')->willReturnCallback(static fn (User $u, ?Tenant $t, string $m): bool => 'pastas' === $m);

        self::assertSame([true, false], [(new PermissoesDaAgenda($soPrazos))->verPrazos(new User(), new Tenant()), (new PermissoesDaAgenda($soPrazos))->verPastas(new User(), new Tenant())]);
        self::assertSame([false, true], [(new PermissoesDaAgenda($soPastas))->verPrazos(new User(), new Tenant()), (new PermissoesDaAgenda($soPastas))->verPastas(new User(), new Tenant())]);
    }
}
