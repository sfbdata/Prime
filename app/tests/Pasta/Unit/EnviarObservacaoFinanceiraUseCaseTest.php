<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaObservacaoFinanceira;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\EnviarObservacaoFinanceiraUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnviarObservacaoFinanceiraUseCase::class)]
final class EnviarObservacaoFinanceiraUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EnviarObservacaoFinanceiraUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EnviarObservacaoFinanceiraUseCase($this->em);

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com')->setTenant($this->tenant);
        $this->pasta  = new Pasta();
    }

    public function testEnviarObservacaoCriaEntidade(): void
    {
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(PastaObservacaoFinanceira::class));
        $this->em->expects($this->once())->method('flush');

        $obs = $this->useCase->executar($this->pasta, $this->autor, 'Observação de teste');

        self::assertSame('Observação de teste', $obs->getConteudo());
        self::assertSame($this->pasta, $obs->getPasta());
        self::assertSame($this->autor, $obs->getAutor());
        self::assertSame($this->tenant, $obs->getTenant());
    }

    public function testConteudoVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ');
    }

    public function testConteudoAcimaDe5000CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, str_repeat('a', 5001));
    }

    public function testUsuarioSemTenantLancaLogicException(): void
    {
        $autorSemTenant = (new User())->setEmail('semtenant@test.com');

        $this->em->expects($this->never())->method('persist');

        $this->expectException(\LogicException::class);

        $this->useCase->executar($this->pasta, $autorSemTenant, 'Conteúdo válido');
    }
}
