<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PrioridadePasta;
use App\Pasta\UseCase\AlterarPrioridadeUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlterarPrioridadeUseCase::class)]
final class AlterarPrioridadeUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AlterarPrioridadeUseCase $useCase;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new AlterarPrioridadeUseCase($this->em);
    }

    public function testAlterarParaUrgentePersiste(): void
    {
        $pasta = new Pasta();

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, PrioridadePasta::Urgente);

        self::assertSame(PrioridadePasta::Urgente, $pasta->getPrioridade());
    }

    public function testAlterarParaPrioridadePersiste(): void
    {
        $pasta = new Pasta();
        $pasta->setPrioridade(PrioridadePasta::Urgente);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, PrioridadePasta::Prioridade);

        self::assertSame(PrioridadePasta::Prioridade, $pasta->getPrioridade());
    }

    public function testAlterarParaNormalPersiste(): void
    {
        $pasta = new Pasta();
        $pasta->setPrioridade(PrioridadePasta::Urgente);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, PrioridadePasta::Normal);

        self::assertSame(PrioridadePasta::Normal, $pasta->getPrioridade());
    }

    public function testPrioridadeInicialEhNormal(): void
    {
        $pasta = new Pasta();

        self::assertSame(PrioridadePasta::Normal, $pasta->getPrioridade());
    }
}
