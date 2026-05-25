<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\AdicionarChecklistItemUseCase;
use App\Pasta\Repository\PastaChecklistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdicionarChecklistItemUseCase::class)]
final class AdicionarChecklistItemUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaChecklistItemRepository&MockObject $repo;
    private AdicionarChecklistItemUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em   = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(PastaChecklistItemRepository::class);
        $this->useCase = new AdicionarChecklistItemUseCase($this->em, $this->repo);

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = new Pasta();
    }

    public function testAdicionarItemCriaEntidade(): void
    {
        $this->repo->expects($this->once())
            ->method('proximaOrdem')
            ->willReturn(1);

        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(PastaChecklistItem::class));
        $this->em->expects($this->once())->method('flush');

        $item = $this->useCase->executar($this->pasta, $this->autor, 'Documento de identidade', $this->tenant);

        self::assertSame('DOCUMENTO DE IDENTIDADE', $item->getTitulo());
        self::assertSame(1, $item->getOrdem());
        self::assertFalse($item->isConcluido());
        self::assertSame($this->pasta, $item->getPasta());
        self::assertSame($this->tenant, $item->getTenant());
    }

    public function testTituloComEspacosEhTrimado(): void
    {
        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->em->method('persist');
        $this->em->method('flush');

        $item = $this->useCase->executar($this->pasta, $this->autor, '  Peça processual  ', $this->tenant);

        self::assertSame('PEÇA PROCESSUAL', $item->getTitulo());
    }

    public function testTituloVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ', $this->tenant);
    }

    public function testTituloAcimaDe255CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, str_repeat('a', 256), $this->tenant);
    }
}
