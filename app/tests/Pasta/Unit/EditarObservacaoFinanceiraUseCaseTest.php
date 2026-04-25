<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PastaObservacaoFinanceira;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\EditarObservacaoFinanceiraUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarObservacaoFinanceiraUseCase::class)]
final class EditarObservacaoFinanceiraUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EditarObservacaoFinanceiraUseCase $useCase;
    private PastaObservacaoFinanceira $observacao;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EditarObservacaoFinanceiraUseCase($this->em);

        $tenant = new Tenant();
        $autor  = (new User())->setEmail('autor@test.com')->setTenant($tenant);

        $this->observacao = new PastaObservacaoFinanceira();
        $this->observacao->setPasta(new Pasta());
        $this->observacao->setAutor($autor);
        $this->observacao->setTenant($tenant);
        $this->observacao->setConteudo('Conteúdo original');
    }

    public function testEditarAtualizaConteudoEDataEdicao(): void
    {
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->observacao, 'Novo conteúdo');

        self::assertSame('Novo conteúdo', $this->observacao->getConteudo());
        self::assertNotNull($this->observacao->getEditadaEm());
    }

    public function testConteudoVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->observacao, '   ');
    }

    public function testConteudoAcimaDe5000CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->observacao, str_repeat('b', 5001));
    }
}
