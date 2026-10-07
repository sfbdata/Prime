<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Exception\DesfazerReaberturaRecusadoException;
use App\Tarefa\UseCase\DesfazerReaberturaDaMetaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PMC-061 — o "Desfazer" de um reabrir devolve a data de conclusão ORIGINAL, nunca a de agora.
 */
#[CoversClass(DesfazerReaberturaDaMetaUseCase::class)]
final class DesfazerReaberturaDaMetaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DesfazerReaberturaDaMetaUseCase $useCase;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new DesfazerReaberturaDaMetaUseCase($this->em);
        $this->tenant  = new Tenant();
    }

    /** Meta reaberta (pendente, sem data de conclusão), criada em `$criadaEm`. */
    private function metaReaberta(string $criadaEm = '2026-09-01 08:00'): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Protocolar recurso');
        $tarefa->setDescricao('...');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($this->tenant);
        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($tarefa, new \DateTimeImmutable($criadaEm));

        return $tarefa;
    }

    #[TestDox('restaura: volta a concluída com a data ORIGINAL (não a de agora) e dá flush')]
    public function testRestauraADataOriginal(): void
    {
        $tarefa   = $this->metaReaberta();
        $original = new \DateTimeImmutable('2026-09-10 10:00');
        $this->em->expects(self::once())->method('flush');

        self::assertTrue($this->useCase->executar($tarefa, $this->tenant, $original, new \DateTimeImmutable('2026-10-07 16:00')));
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
        self::assertSame('2026-09-10 10:00:00', $tarefa->getDataConclusao()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('limites aceitos: a data igual à criação e igual a agora')]
    public function testLimitesAceitos(): void
    {
        $agora = new \DateTimeImmutable('2026-10-07 16:00');

        $tarefa = $this->metaReaberta('2026-09-01 08:00');
        self::assertTrue($this->useCase->executar($tarefa, $this->tenant, new \DateTimeImmutable('2026-09-01 08:00'), $agora));

        $outra = $this->metaReaberta('2026-09-01 08:00');
        self::assertTrue($this->useCase->executar($outra, $this->tenant, $agora, $agora));
    }

    #[TestDox('meta que já está concluída: nada a desfazer (false), sem flush e sem trocar a data')]
    public function testJaConcluida(): void
    {
        $tarefa = $this->metaReaberta();
        $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);
        $tarefa->setDataConclusao(new \DateTimeImmutable('2026-10-07 15:00'));
        $this->em->expects(self::never())->method('flush');

        self::assertFalse($this->useCase->executar($tarefa, $this->tenant, new \DateTimeImmutable('2026-09-10 10:00'), new \DateTimeImmutable('2026-10-07 16:00')));
        self::assertSame('2026-10-07 15:00:00', $tarefa->getDataConclusao()?->format('Y-m-d H:i:s'));
    }

    /** @return iterable<string, array{?string}> */
    public static function datasRecusadas(): iterable
    {
        yield 'sem data lembrada' => [null];
        yield 'no futuro' => ['2026-10-07 16:00:01'];
        yield 'antes da criação da meta' => ['2026-09-01 07:59:59'];
    }

    #[DataProvider('datasRecusadas')]
    #[TestDox('recusa sem data lembrada ou com data impossível — e NÃO conclui com a data de agora')]
    public function testRecusa(?string $data): void
    {
        $tarefa = $this->metaReaberta('2026-09-01 08:00');
        $this->em->expects(self::never())->method('flush');

        try {
            $this->useCase->executar($tarefa, $this->tenant, $data === null ? null : new \DateTimeImmutable($data), new \DateTimeImmutable('2026-10-07 16:00'));
            self::fail('devia recusar');
        } catch (DesfazerReaberturaRecusadoException $e) {
            self::assertStringContainsString('Não foi possível desfazer', $e->getMessage());
        }

        self::assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());
        self::assertNull($tarefa->getDataConclusao());
    }

    #[TestDox('meta de outro escritório: LogicException, nada muda')]
    public function testOutroEscritorio(): void
    {
        $tarefa = $this->metaReaberta();
        $this->em->expects(self::never())->method('flush');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Meta de outro escritório.');
        $this->useCase->executar($tarefa, new Tenant(), new \DateTimeImmutable('2026-09-10 10:00'), new \DateTimeImmutable('2026-10-07 16:00'));
    }
}
