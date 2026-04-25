<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaChecklistItem;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\AdicionarChecklistItemUseCase;
use App\Repository\Pasta\PastaChecklistItemRepository;
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

    protected function setUp(): void
    {
        $this->em   = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(PastaChecklistItemRepository::class);
        $this->useCase = new AdicionarChecklistItemUseCase($this->em, $this->repo);

        $tenant      = new Tenant();
        $this->autor = (new User())->setEmail('autor@test.com')->setTenant($tenant);
        $this->pasta = new Pasta();
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

        $item = $this->useCase->executar($this->pasta, $this->autor, 'Documento de identidade');

        self::assertSame('Documento de identidade', $item->getTitulo());
        self::assertSame(1, $item->getOrdem());
        self::assertFalse($item->isConcluido());
        self::assertSame($this->pasta, $item->getPasta());
        self::assertSame($this->autor->getTenant(), $item->getTenant());
    }

    public function testTituloComEspacosEhTrimado(): void
    {
        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->em->method('persist');
        $this->em->method('flush');

        $item = $this->useCase->executar($this->pasta, $this->autor, '  Peça processual  ');

        self::assertSame('Peça processual', $item->getTitulo());
    }

    public function testTituloVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ');
    }

    public function testTituloAcimaDe255CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, str_repeat('a', 256));
    }

    public function testUsuarioSemTenantLancaLogicException(): void
    {
        $autorSemTenant = (new User())->setEmail('semtenant@test.com');

        $this->em->expects($this->never())->method('persist');

        $this->expectException(\LogicException::class);

        $this->useCase->executar($this->pasta, $autorSemTenant, 'Título válido');
    }
}
