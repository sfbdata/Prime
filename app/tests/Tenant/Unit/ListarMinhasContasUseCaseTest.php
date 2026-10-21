<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Repository\EquipeDoEscritorioRepository;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tenant\UseCase\ListarMinhasContasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarMinhasContasUseCase::class)]
final class ListarMinhasContasUseCaseTest extends TestCase
{
    #[TestDox('monta cada conta com equipe, titular e selo de atual, em ordem alfabética')]
    public function testMontaAsContasDaPessoa(): void
    {
        $eu      = $this->usuario(1, 'Jéssica Martins');
        $titular = $this->usuario(2, 'Farlei Assis da Rocha');
        $b       = $this->tenant(10, 'Bravo Advogados', $titular);
        $a       = $this->tenant(20, 'Alfa Advocacia', $eu);

        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findActiveByUser')->willReturn([new UserTenant($eu, $b), new UserTenant($eu, $a)]);
        $equipe = $this->createMock(EquipeDoEscritorioRepository::class);
        $equipe->expects(self::once())->method('contarAtivosPorTenant')->with([10, 20])->willReturn([10 => 8, 20 => 1]);

        $contas = (new ListarMinhasContasUseCase($vinculos, $equipe, $this->semPedido()))->executar($eu, 10);

        self::assertSame(['Alfa Advocacia', 'Bravo Advogados'], array_map(static fn ($c): string => $c->nome, $contas));
        self::assertTrue($contas[0]->souTitular);
        self::assertFalse($contas[0]->atual);
        self::assertSame(8, $contas[1]->usuarios);
        self::assertSame('Farlei Assis da Rocha', $contas[1]->primarioNome);
        self::assertTrue($contas[1]->atual);
        self::assertFalse($contas[1]->souTitular);
    }

    #[TestDox('sem vínculo ativo, devolve lista vazia sem contar ninguém')]
    public function testSemVinculos(): void
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findActiveByUser')->willReturn([]);
        $equipe = $this->createMock(EquipeDoEscritorioRepository::class);
        $equipe->method('contarAtivosPorTenant')->with([])->willReturn([]);

        self::assertSame([], (new ListarMinhasContasUseCase($vinculos, $equipe, $this->semPedido()))->executar($this->usuario(1, 'X'), null));
    }

    private function usuario(int $id, string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }

    private function tenant(int $id, string $nome, ?User $titular): Tenant
    {
        $t = new Tenant();
        $t->setName($nome);
        $t->setCriadoPor($titular);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($t, $id);

        return $t;
    }

    /** Sem pedido de exclusão pendente: nenhuma conta é barrada (PR 5). */
    private function semPedido(): BloqueioPorPedidoDeExclusao
    {
        return new BloqueioPorPedidoDeExclusao($this->createStub(PedidoDeExclusaoDeContaRepository::class), new RegraDosPrimarios($this->createStub(UserTenantRepository::class)));
    }
}
