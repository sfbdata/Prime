<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaChecklistItem;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\ExcluirChecklistItemUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirChecklistItemUseCase::class)]
final class ExcluirChecklistItemUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ExcluirChecklistItemUseCase $useCase;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ExcluirChecklistItemUseCase($this->em);
    }

    public function testExcluirChamaRemoveEFlush(): void
    {
        $item = new PastaChecklistItem();
        $item->setPasta(new Pasta());
        $item->setTenant(new Tenant());
        $item->setOrdem(1);

        $this->em->expects($this->once())->method('remove')->with($item);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($item);
    }

    public function testExcluirItemSemPastaFunciona(): void
    {
        $item = new PastaChecklistItem();

        $this->em->expects($this->once())->method('remove');
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($item);
    }
}
