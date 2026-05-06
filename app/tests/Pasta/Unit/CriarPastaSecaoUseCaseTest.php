<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\CriarPastaSecaoUseCase;
use App\Repository\Pasta\PastaSecaoRepository;
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

    protected function setUp(): void
    {
        $this->em   = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(PastaSecaoRepository::class);
        $this->useCase = new CriarPastaSecaoUseCase($this->em, $this->repo);

        $tenant      = new Tenant();
        $this->autor = (new User())->setEmail('autor@test.com')->setTenant($tenant);
        $this->pasta = new Pasta();
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

        $secao = $this->useCase->executar($this->pasta, $this->autor, 'Petições');

        self::assertSame('PETIÇÕES', $secao->getNome());
        self::assertSame(1, $secao->getOrdem());
        self::assertSame($this->pasta, $secao->getPasta());
        self::assertSame($this->autor->getTenant(), $secao->getTenant());
    }

    public function testNomeComEspacosEhTrimado(): void
    {
        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->em->method('persist');
        $this->em->method('flush');

        $secao = $this->useCase->executar($this->pasta, $this->autor, '  Contratos  ');

        self::assertSame('CONTRATOS', $secao->getNome());
    }

    public function testNomeVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ');
    }

    public function testNomeAcimaDe255CaracteresLancaExcecao(): void
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

        $this->useCase->executar($this->pasta, $autorSemTenant, 'Nome válido');
    }
}
