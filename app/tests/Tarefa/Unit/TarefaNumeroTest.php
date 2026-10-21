<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O número da meta no escritório ("Nº 0001"): nasce nulo, é dado uma vez só e começa em 1. */
#[CoversClass(Tarefa::class)]
final class TarefaNumeroTest extends TestCase
{
    #[TestDox('Meta nova nasce sem número (a tela não mostra selo)')]
    public function testNasceSemNumero(): void
    {
        self::assertNull((new Tarefa())->getNumero());
    }

    #[TestDox('numerar() grava o número')]
    public function testNumerarGrava(): void
    {
        $meta = (new Tarefa())->numerar(42);

        self::assertSame(42, $meta->getNumero());
    }

    #[TestDox('Meta já numerada não troca de número')]
    public function testNaoRenumera(): void
    {
        $meta = (new Tarefa())->numerar(3);

        $this->expectException(\LogicException::class);
        $meta->numerar(4);
    }

    #[TestDox('O número começa em 1: zero e negativo são recusados')]
    public function testRecusaZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Tarefa())->numerar(0);
    }
}
