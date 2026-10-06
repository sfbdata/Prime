<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\UseCase\ReabrirMetaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReabrirMetaUseCase::class)]
final class ReabrirMetaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ReabrirMetaUseCase $useCase;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ReabrirMetaUseCase($this->em);
        $this->tenant  = new Tenant();
    }

    private function meta(string $status): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Protocolar recurso');
        $tarefa->setDescricao('...');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($this->tenant);
        $tarefa->setStatus($status);
        if ($status === Tarefa::STATUS_CONCLUIDA) {
            $tarefa->setDataConclusao(new \DateTimeImmutable('2026-09-10 10:00'));
        }

        return $tarefa;
    }

    #[TestDox('concluída volta a pendente, sem data de conclusão, e dá flush')]
    public function testReabre(): void
    {
        $tarefa = $this->meta(Tarefa::STATUS_CONCLUIDA);
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->useCase->executar($tarefa, $this->tenant));
        self::assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());
        self::assertNull($tarefa->getDataConclusao());
    }

    /** @return iterable<string, array{string}> */
    public static function abertas(): iterable
    {
        yield 'pendente' => [Tarefa::STATUS_PENDENTE];
        yield 'em revisão' => [Tarefa::STATUS_EM_REVISAO];
    }

    #[DataProvider('abertas')]
    #[TestDox('meta que não está concluída ($_dataName) não muda')]
    public function testNaoConcluidaNaoMuda(string $status): void
    {
        $tarefa = $this->meta($status);
        $this->em->expects(self::never())->method('flush');

        self::assertFalse($this->useCase->executar($tarefa, $this->tenant));
        self::assertSame($status, $tarefa->getStatus());
    }

    #[TestDox('meta de outro escritório: recusa sem gravar')]
    public function testOutroEscritorio(): void
    {
        $tarefa = $this->meta(Tarefa::STATUS_CONCLUIDA);
        $this->em->expects(self::never())->method('flush');

        try {
            $this->useCase->executar($tarefa, new Tenant());
            self::fail('deveria recusar');
        } catch (\LogicException) {
        }

        self::assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
        self::assertNotNull($tarefa->getDataConclusao());
    }
}
