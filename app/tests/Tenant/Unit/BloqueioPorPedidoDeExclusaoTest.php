<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** PR 5 (E3): com pedido pendente, só o primário entra; sem pedido, ninguém é barrado. */
#[CoversClass(BloqueioPorPedidoDeExclusao::class)]
final class BloqueioPorPedidoDeExclusaoTest extends TestCase
{
    private function vinculo(Tenant $conta, bool $primario): UserTenant
    {
        $user = (new User())->setIsActive(true)->setFullName('X');
        $papel = (new TenantRole())->setIsSystem($primario);

        return (new UserTenant($user, $conta))->setTenantRole($papel);
    }

    private function regra(bool $comPedido): BloqueioPorPedidoDeExclusao
    {
        $pedidos = $this->createStub(PedidoDeExclusaoDeContaRepository::class);
        $pedidos->method('existePendente')->willReturn($comPedido);

        return new BloqueioPorPedidoDeExclusao($pedidos, new RegraDosPrimarios($this->createStub(UserTenantRepository::class)));
    }

    #[TestDox('com pedido: a equipe é barrada e sai da lista; o primário passa')]
    public function testComPedido(): void
    {
        $conta = (new Tenant())->setName('Conta A');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($conta, 7);
        $primario = $this->vinculo($conta, true);
        $equipe = $this->vinculo($conta, false);
        $regra = $this->regra(true);

        self::assertFalse($regra->bloqueia($primario));
        self::assertTrue($regra->bloqueia($equipe));
        self::assertSame([$primario], $regra->permitidos([$primario, $equipe]));
        self::assertSame(['Conta A'], $regra->nomesBloqueados([$primario, $equipe]));
    }

    #[TestDox('sem pedido: ninguém é barrado')]
    public function testSemPedido(): void
    {
        $conta = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($conta, 7);
        $equipe = $this->vinculo($conta, false);

        self::assertFalse($this->regra(false)->bloqueia($equipe));
        self::assertSame([$equipe], $this->regra(false)->permitidos([$equipe]));
    }
}
