<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\CriarPastaSecaoUseCase;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarPastaSecaoUseCase::class)]
final class CriarPastaSecaoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaSecaoRepository&MockObject $repo;
    private CriarPastaSecaoUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em   = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(PastaSecaoRepository::class);
        $this->useCase = new CriarPastaSecaoUseCase($this->em, $this->repo);

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = new Pasta();
    }

    public function testCriarSecaoPersisteDadosCorretamente(): void
    {
        $this->repo->expects($this->once())
            ->method('proximaOrdem')
            ->willReturn(1);

        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(PastaSecao::class));
        $this->em->expects($this->once())->method('flush');

        $secao = $this->useCase->executar($this->pasta, $this->autor, 'Petições', $this->tenant);

        self::assertSame('PETIÇÕES', $secao->getNome());
        self::assertSame(1, $secao->getOrdem());
        self::assertSame($this->pasta, $secao->getPasta());
        self::assertSame($this->tenant, $secao->getTenant());
    }

    public function testNomeComEspacosEhTrimado(): void
    {
        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->em->method('persist');
        $this->em->method('flush');

        $secao = $this->useCase->executar($this->pasta, $this->autor, '  Contratos  ', $this->tenant);

        self::assertSame('CONTRATOS', $secao->getNome());
    }

    public function testNomeVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ', $this->tenant);
    }

    public function testNomeAcimaDe255CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, str_repeat('a', 256), $this->tenant);
    }
}
