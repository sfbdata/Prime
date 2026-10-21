<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Enum\EstadoDoPedidoDeExclusao;
use App\Tenant\UseCase\SolicitarCodigoDeExclusaoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** PR 5: as transições do pedido (contrato com a b5) e o hash do código por par escritório + usuário. */
#[CoversClass(PedidoDeExclusaoDeConta::class)]
#[CoversClass(SolicitarCodigoDeExclusaoUseCase::class)]
final class PedidoDeExclusaoDeContaTest extends TestCase
{
    private function pedido(): PedidoDeExclusaoDeConta
    {
        return new PedidoDeExclusaoDeConta(new Tenant(), new User(), 'Achei caro', new \DateTimeImmutable('2026-10-20 10:00'));
    }

    /** @return iterable<string, array{string, EstadoDoPedidoDeExclusao}> */
    public static function transicoes(): iterable
    {
        yield 'primário desiste' => ['desistir', EstadoDoPedidoDeExclusao::Desistida];
        yield 'Master reativa' => ['marcarReativada', EstadoDoPedidoDeExclusao::Reativada];
        yield 'Master exclui' => ['marcarExcluida', EstadoDoPedidoDeExclusao::Excluida];
    }

    #[DataProvider('transicoes')]
    #[TestDox('nasce pendente; cada decisão grava estado, quem e quando, e só vale uma vez')]
    public function testTransicoes(string $metodo, EstadoDoPedidoDeExclusao $esperado): void
    {
        $pedido = $this->pedido();
        self::assertTrue($pedido->estaPendente());
        $ator = new User();
        $em = new \DateTimeImmutable('2026-10-21 09:00');

        $pedido->{$metodo}($ator, $em);

        self::assertSame($esperado, $pedido->getEstado());
        self::assertSame($ator, $pedido->getDecididoPor());
        self::assertSame($em, $pedido->getDecididoEm());
        $this->expectException(\DomainException::class);
        $pedido->marcarExcluida($ator);
    }

    #[TestDox('o hash do código muda com o escritório e com o usuário: não serve para outro par')]
    public function testHashPorPar(): void
    {
        $tenantA = new Tenant();
        $tenantB = new Tenant();
        $u1 = new User();
        $u2 = new User();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenantA, 1);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenantB, 2);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u1, 10);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u2, 11);

        $base = SolicitarCodigoDeExclusaoUseCase::hash('segredo', $tenantA, $u1, '123456');

        self::assertSame(64, strlen($base));
        self::assertNotSame($base, SolicitarCodigoDeExclusaoUseCase::hash('segredo', $tenantB, $u1, '123456'));
        self::assertNotSame($base, SolicitarCodigoDeExclusaoUseCase::hash('segredo', $tenantA, $u2, '123456'));
        self::assertNotSame($base, SolicitarCodigoDeExclusaoUseCase::hash('outro', $tenantA, $u1, '123456'));
    }
}
