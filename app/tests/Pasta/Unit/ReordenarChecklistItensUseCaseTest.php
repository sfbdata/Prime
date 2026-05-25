<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\ReordenarChecklistItensUseCase;
use App\Pasta\Repository\PastaChecklistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReordenarChecklistItensUseCase::class)]
final class ReordenarChecklistItensUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaChecklistItemRepository&MockObject $repo;
    private ReordenarChecklistItensUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->repo   = $this->createMock(PastaChecklistItemRepository::class);
        $this->useCase = new ReordenarChecklistItensUseCase($this->em, $this->repo);

        $this->pasta  = new Pasta();
        $this->tenant = new Tenant();
    }

    private function criarItem(int $id, int $ordem): PastaChecklistItem
    {
        $item = new PastaChecklistItem();
        $item->setOrdem($ordem);

        $ref = new \ReflectionProperty(PastaChecklistItem::class, 'id');
        $ref->setValue($item, $id);

        return $item;
    }

    public function testReordenarAtualizaOrdemCorretamente(): void
    {
        $item1 = $this->criarItem(1, 1);
        $item2 = $this->criarItem(2, 2);
        $item3 = $this->criarItem(3, 3);

        $this->repo->method('findByPasta')->willReturn([$item1, $item2, $item3]);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->pasta, $this->tenant, [3, 1, 2]);

        self::assertSame(1, $item3->getOrdem());
        self::assertSame(2, $item1->getOrdem());
        self::assertSame(3, $item2->getOrdem());
    }

    public function testArrayVazioNaoChamaFlush(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->useCase->executar($this->pasta, $this->tenant, []);
    }

    public function testIdInvalidoIgnoradoSilenciosamente(): void
    {
        $item1 = $this->criarItem(1, 1);

        $this->repo->method('findByPasta')->willReturn([$item1]);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->pasta, $this->tenant, [1, 99]);

        self::assertSame(1, $item1->getOrdem());
    }
}
