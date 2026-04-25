<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Pasta\PastaChecklistItem;
use App\Pasta\UseCase\EditarChecklistItemUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarChecklistItemUseCase::class)]
final class EditarChecklistItemUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EditarChecklistItemUseCase $useCase;
    private PastaChecklistItem $item;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EditarChecklistItemUseCase($this->em);

        $this->item = new PastaChecklistItem();
        $this->item->setTitulo('Título original');
    }

    public function testEditarAtualizaTitulo(): void
    {
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->item, 'Novo título');

        self::assertSame('Novo título', $this->item->getTitulo());
    }

    public function testTituloComEspacosEhTrimado(): void
    {
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->item, '  Procuração  ');

        self::assertSame('Procuração', $this->item->getTitulo());
    }

    public function testTituloVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->item, '   ');
    }

    public function testTituloAcimaDe255CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->item, str_repeat('x', 256));
    }

    public function testTituloExatamente255CaracteresEValido(): void
    {
        $this->em->expects($this->once())->method('flush');

        $titulo255 = str_repeat('a', 255);
        $this->useCase->executar($this->item, $titulo255);

        self::assertSame($titulo255, $this->item->getTitulo());
    }
}
