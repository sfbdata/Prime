<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\UseCase\HerdarPrioridadeDaPastaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(HerdarPrioridadeDaPastaUseCase::class)]
final class HerdarPrioridadeDaPastaUseCaseTest extends TestCase
{
    #[TestDox('Simular só conta, nunca grava')]
    public function testSimularNaoGrava(): void
    {
        $repo = $this->createMock(TarefaRepository::class);
        $repo->method('contarHerancaDePrioridadePendente')->with(7)->willReturn([7 => 3]);
        $repo->expects(self::never())->method('herdarPrioridadeDaPasta');

        self::assertSame([7 => 3], (new HerdarPrioridadeDaPastaUseCase($repo))->simular(7));
    }

    #[TestDox('Executar grava escritório por escritório, só os que têm meta a herdar, com o id explícito')]
    public function testExecutarPorEscritorio(): void
    {
        $repo = $this->createMock(TarefaRepository::class);
        $repo->method('contarHerancaDePrioridadePendente')->with(null)->willReturn([2 => 5, 9 => 1]);
        $repo->expects(self::exactly(2))->method('herdarPrioridadeDaPasta')
            ->willReturnCallback(static fn (int $tenantId): int => $tenantId === 2 ? 5 : 1);

        self::assertSame([2 => 5, 9 => 1], (new HerdarPrioridadeDaPastaUseCase($repo))->executar());
    }

    #[TestDox('Sem nada a herdar, nada é gravado')]
    public function testNadaAHerdar(): void
    {
        $repo = $this->createMock(TarefaRepository::class);
        $repo->method('contarHerancaDePrioridadePendente')->willReturn([]);
        $repo->expects(self::never())->method('herdarPrioridadeDaPasta');

        self::assertSame([], (new HerdarPrioridadeDaPastaUseCase($repo))->executar());
    }
}
