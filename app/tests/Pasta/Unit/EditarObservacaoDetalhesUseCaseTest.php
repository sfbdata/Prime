<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Exception\ObservacaoDetalhesNaoEditavelException;
use App\Pasta\UseCase\EditarObservacaoDetalhesUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarObservacaoDetalhesUseCase::class)]
final class EditarObservacaoDetalhesUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EditarObservacaoDetalhesUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EditarObservacaoDetalhesUseCase($this->em);
        $this->tenant  = new Tenant();
        $this->autor   = (new User())->setEmail('autor@test.com');
    }

    private function novaObservacao(string $conteudo = 'Original'): PastaObservacaoDetalhes
    {
        return (new PastaObservacaoDetalhes())
            ->setPasta(new Pasta())
            ->setAutor($this->autor)
            ->setTenant($this->tenant)
            ->setConteudo($conteudo);
    }

    public function testAutorDentroDaJanelaEdita(): void
    {
        $obs = $this->novaObservacao();
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($obs, $this->autor, $this->tenant, 'Corrigido');

        self::assertSame('Corrigido', $obs->getConteudo());
        self::assertNotNull($obs->getEditadaEm());
    }

    public function testNaoAutorLancaExcecao(): void
    {
        $obs   = $this->novaObservacao();
        $outro = (new User())->setEmail('outro@test.com');

        $this->em->expects($this->never())->method('flush');

        $this->expectException(ObservacaoDetalhesNaoEditavelException::class);

        $this->useCase->executar($obs, $outro, $this->tenant, 'Corrigido');
    }

    public function testTenantDiferenteLancaExcecao(): void
    {
        $obs = $this->novaObservacao();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(ObservacaoDetalhesNaoEditavelException::class);

        $this->useCase->executar($obs, $this->autor, new Tenant(), 'Corrigido');
    }

    public function testConteudoVazioLancaInvalidArgument(): void
    {
        $obs = $this->novaObservacao();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($obs, $this->autor, $this->tenant, '   ');
    }

    public function testConteudoAcimaDe5000LancaInvalidArgument(): void
    {
        $obs = $this->novaObservacao();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($obs, $this->autor, $this->tenant, str_repeat('a', 5001));
    }

    public function testPodeEditarRetornaFalseForaDaJanela(): void
    {
        $obs      = $this->novaObservacao();
        $expirado = $obs->getCriadaEm()->add(new \DateInterval('PT25H'));

        self::assertFalse($this->useCase->podeEditar($obs, $this->autor, $this->tenant, $expirado));
    }

    public function testPodeEditarRetornaTrueDentroDaJanela(): void
    {
        $obs    = $this->novaObservacao();
        $dentro = $obs->getCriadaEm()->add(new \DateInterval('PT23H'));

        self::assertTrue($this->useCase->podeEditar($obs, $this->autor, $this->tenant, $dentro));
    }
}
