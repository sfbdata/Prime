<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Pasta\PastaChecklistItem;
use App\Pasta\UseCase\ToggleChecklistItemUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToggleChecklistItemUseCase::class)]
final class ToggleChecklistItemUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ToggleChecklistItemUseCase $useCase;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ToggleChecklistItemUseCase($this->em);
    }

    public function testToggleDePendenteParaConcluido(): void
    {
        $item = new PastaChecklistItem();
        $item->setConcluido(false);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($item);

        self::assertTrue($item->isConcluido());
    }

    public function testToggleDeConcluidoParaPendente(): void
    {
        $item = new PastaChecklistItem();
        $item->setConcluido(true);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($item);

        self::assertFalse($item->isConcluido());
    }

    public function testToggleDuasVezesRetornaEstadoOriginal(): void
    {
        $item = new PastaChecklistItem();
        $item->setConcluido(false);

        $this->em->expects($this->exactly(2))->method('flush');

        $this->useCase->executar($item);
        $this->useCase->executar($item);

        self::assertFalse($item->isConcluido());
    }
}
