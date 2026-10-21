<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Tenant\Service\ColaboradoresAtribuiveis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ColaboradoresAtribuiveis::class)]
final class ColaboradoresAtribuiveisTest extends TestCase
{
    #[TestDox('aprovados mais quem já está no registro (mesmo suspenso), sem repetir, por nome')]
    public function testAprovadosMaisAtuais(): void
    {
        [$ana, $bruno, $suspensa] = [$this->usuario(1, 'Ana'), $this->usuario(2, 'Bruno'), $this->usuario(3, 'Carla Suspensa')];
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findColaboradoresAtivosPorTenant')->willReturn([$bruno, $ana]);

        $lista = (new ColaboradoresAtribuiveis($repo))->doEscritorio(new Tenant(), [$suspensa, $ana, null]);

        self::assertSame(['Ana', 'Bruno', 'Carla Suspensa'], array_map(static fn (User $u): string => (string) $u->getFullName(), $lista));
    }

    #[TestDox('sem registro atual, só os aprovados')]
    public function testSoAprovados(): void
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findColaboradoresAtivosPorTenant')->willReturn([$this->usuario(1, 'Ana')]);

        self::assertCount(1, (new ColaboradoresAtribuiveis($repo))->doEscritorio(new Tenant()));
    }

    private function usuario(int $id, string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
