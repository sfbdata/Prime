<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Spec memoria-do-layout: a pergunta "tem pedido pendente?" sai uma vez por escritório na requisição. */
#[CoversClass(BloqueioPorPedidoDeExclusao::class)]
final class BloqueioPorPedidoDeExclusaoMemoriaTest extends TestCase
{
    private function escritorio(int $id): Tenant
    {
        $tenant = (new Tenant())->setName('Conta ' . $id);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    #[TestDox('N perguntas pelo mesmo escritório: uma consulta; outro escritório é outra chave')]
    public function testUmaConsultaPorEscritorio(): void
    {
        $pedidos = $this->createMock(PedidoDeExclusaoDeContaRepository::class);
        $pedidos->expects(self::exactly(2))->method('existePendente')
            ->willReturnCallback(static fn (int $id): bool => $id === 7);
        $regra = new BloqueioPorPedidoDeExclusao($pedidos, new RegraDosPrimarios($this->createStub(UserTenantRepository::class)));

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($regra->suspensaAPedido(7));
            self::assertFalse($regra->suspensaAPedido(8));
        }
    }

    #[TestDox('reset() esvazia a memória')]
    public function testResetEsvazia(): void
    {
        $pedidos = $this->createMock(PedidoDeExclusaoDeContaRepository::class);
        $pedidos->expects(self::exactly(2))->method('existePendente')->willReturn(false);
        $regra = new BloqueioPorPedidoDeExclusao($pedidos, new RegraDosPrimarios($this->createStub(UserTenantRepository::class)));

        $regra->suspensaAPedido(7);
        $regra->reset();
        $regra->suspensaAPedido(7);
    }

    #[TestDox('Pedido gravado esquece só o escritório dele')]
    public function testPedidoGravadoEsqueceOEscritorio(): void
    {
        $pedidos = $this->createMock(PedidoDeExclusaoDeContaRepository::class);
        $pedidos->expects(self::exactly(3))->method('existePendente')
            ->willReturnOnConsecutiveCalls(false, false, true);
        $regra = new BloqueioPorPedidoDeExclusao($pedidos, new RegraDosPrimarios($this->createStub(UserTenantRepository::class)));

        self::assertFalse($regra->suspensaAPedido(7));
        self::assertFalse($regra->suspensaAPedido(8));
        $regra->esquecerPedido(new PedidoDeExclusaoDeConta($this->escritorio(7), new User(), 'motivo', new \DateTimeImmutable()));

        self::assertTrue($regra->suspensaAPedido(7));
        self::assertFalse($regra->suspensaAPedido(8));
    }
}
