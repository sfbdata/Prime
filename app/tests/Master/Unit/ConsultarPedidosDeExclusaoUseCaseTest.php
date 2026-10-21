<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\UseCase\ConsultarPedidosDeExclusaoUseCase;
use App\Tenant\DTO\PedidoDeExclusaoPendenteOutput;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Linhas do bloco "Pedidos de exclusão de conta" do painel do Master (desenho 05 - Perfis 1.2, linha 148), a
 * partir de PedidoDeExclusaoDeContaRepository::pendentes() (contrato com o PR do pedido de exclusão).
 */
#[CoversClass(ConsultarPedidosDeExclusaoUseCase::class)]
final class ConsultarPedidosDeExclusaoUseCaseTest extends TestCase
{
    #[TestDox('cada pendente vira uma linha com conta, escritório, quem pediu, data ISO e motivo, na ordem do repositório')]
    public function testLinhas(): void
    {
        $repositorio = $this->createMock(PedidoDeExclusaoDeContaRepository::class);
        $repositorio->expects(self::once())->method('pendentes')->willReturn([
            new PedidoDeExclusaoPendenteOutput(7, 'Silva Advogados', 'Ana Primária', 'ana@silva.adv.br', new \DateTimeImmutable('2026-10-18 14:05:00-03:00'), 'Achei caro · muito caro para nós'),
            new PedidoDeExclusaoPendenteOutput(9, 'Souza & Lima', null, null, new \DateTimeImmutable('2026-10-19 08:30:00-03:00'), 'Vou parar de advogar'),
        ]);

        $linhas = (new ConsultarPedidosDeExclusaoUseCase($repositorio))->executar();

        self::assertSame([
            ['contaId' => 7, 'escritorio' => 'Silva Advogados', 'pedidoPorNome' => 'Ana Primária', 'pedidoPorEmail' => 'ana@silva.adv.br', 'criadoEm' => '2026-10-18T14:05:00-03:00', 'motivo' => 'Achei caro · muito caro para nós'],
            ['contaId' => 9, 'escritorio' => 'Souza & Lima', 'pedidoPorNome' => null, 'pedidoPorEmail' => null, 'criadoEm' => '2026-10-19T08:30:00-03:00', 'motivo' => 'Vou parar de advogar'],
        ], $linhas);
    }

    #[TestDox('sem pendentes, lista vazia (o painel esconde o bloco)')]
    public function testSemPendentes(): void
    {
        $repositorio = $this->createStub(PedidoDeExclusaoDeContaRepository::class);
        $repositorio->method('pendentes')->willReturn([]);

        self::assertSame([], (new ConsultarPedidosDeExclusaoUseCase($repositorio))->executar());
    }
}
