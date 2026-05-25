<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\EnviarObservacaoDetalhesUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnviarObservacaoDetalhesUseCase::class)]
final class EnviarObservacaoDetalhesUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EnviarObservacaoDetalhesUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EnviarObservacaoDetalhesUseCase($this->em);

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = new Pasta();
    }

    public function testEnviarObservacaoCriaEntidade(): void
    {
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(PastaObservacaoDetalhes::class));
        $this->em->expects($this->once())->method('flush');

        $obs = $this->useCase->executar($this->pasta, $this->autor, 'Observação de detalhes', $this->tenant);

        self::assertSame('Observação de detalhes', $obs->getConteudo());
        self::assertSame($this->pasta, $obs->getPasta());
        self::assertSame($this->autor, $obs->getAutor());
        self::assertSame($this->tenant, $obs->getTenant());
    }

    public function testConteudoVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ', $this->tenant);
    }

    public function testConteudoAcimaDe5000CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, str_repeat('a', 5001), $this->tenant);
    }
}
