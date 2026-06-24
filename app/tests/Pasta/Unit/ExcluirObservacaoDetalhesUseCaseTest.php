<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Exception\ObservacaoDetalhesNaoExcluivelException;
use App\Pasta\UseCase\ExcluirObservacaoDetalhesUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirObservacaoDetalhesUseCase::class)]
final class ExcluirObservacaoDetalhesUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ExcluirObservacaoDetalhesUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ExcluirObservacaoDetalhesUseCase($this->em);
        $this->tenant  = new Tenant();
        $this->autor   = (new User())->setEmail('autor@test.com');
    }

    private function novaObservacao(): PastaObservacaoDetalhes
    {
        return (new PastaObservacaoDetalhes())
            ->setPasta(new Pasta())
            ->setAutor($this->autor)
            ->setTenant($this->tenant)
            ->setConteudo('Original');
    }

    public function testAutorDentroDaJanelaExclui(): void
    {
        $obs = $this->novaObservacao();
        $this->em->expects($this->once())->method('remove')->with($obs);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($obs, $this->autor, $this->tenant);
    }

    public function testNaoAutorLancaExcecao(): void
    {
        $obs   = $this->novaObservacao();
        $outro = (new User())->setEmail('outro@test.com');

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(ObservacaoDetalhesNaoExcluivelException::class);

        $this->useCase->executar($obs, $outro, $this->tenant);
    }

    public function testTenantDiferenteLancaExcecao(): void
    {
        $obs = $this->novaObservacao();

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(ObservacaoDetalhesNaoExcluivelException::class);

        $this->useCase->executar($obs, $this->autor, new Tenant());
    }

    public function testPodeExcluirRetornaFalseForaDaJanela(): void
    {
        $obs      = $this->novaObservacao();
        $expirado = $obs->getCriadaEm()->add(new \DateInterval('PT25H'));

        self::assertFalse($this->useCase->podeExcluir($obs, $this->autor, $this->tenant, $expirado));
    }

    public function testPodeExcluirRetornaTrueDentroDaJanela(): void
    {
        $obs    = $this->novaObservacao();
        $dentro = $obs->getCriadaEm()->add(new \DateInterval('PT23H'));

        self::assertTrue($this->useCase->podeExcluir($obs, $this->autor, $this->tenant, $dentro));
    }
}
