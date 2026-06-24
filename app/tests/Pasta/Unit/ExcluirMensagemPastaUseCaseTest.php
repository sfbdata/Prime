<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\MensagemPastaNaoExcluivelException;
use App\Pasta\UseCase\ExcluirMensagemPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirMensagemPastaUseCase::class)]
final class ExcluirMensagemPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ExcluirMensagemPastaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ExcluirMensagemPastaUseCase($this->em);
        $this->tenant  = new Tenant();
        $this->autor   = (new User())->setEmail('autor@test.com');
    }

    private function novaMensagem(): PastaMensagem
    {
        return (new PastaMensagem())
            ->setPasta(new Pasta())
            ->setAutor($this->autor)
            ->setTenant($this->tenant)
            ->setConteudo('Original');
    }

    public function testAutorDentroDaJanelaExclui(): void
    {
        $mensagem = $this->novaMensagem();
        $this->em->expects($this->once())->method('remove')->with($mensagem);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($mensagem, $this->autor, $this->tenant);
    }

    public function testNaoAutorLancaExcecao(): void
    {
        $mensagem = $this->novaMensagem();
        $outro    = (new User())->setEmail('outro@test.com');

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(MensagemPastaNaoExcluivelException::class);

        $this->useCase->executar($mensagem, $outro, $this->tenant);
    }

    public function testTenantDiferenteLancaExcecao(): void
    {
        $mensagem = $this->novaMensagem();

        $this->em->expects($this->never())->method('remove');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(MensagemPastaNaoExcluivelException::class);

        $this->useCase->executar($mensagem, $this->autor, new Tenant());
    }

    public function testPodeExcluirRetornaFalseForaDaJanela(): void
    {
        $mensagem = $this->novaMensagem();
        $expirado = $mensagem->getCriadaEm()->add(new \DateInterval('PT25H'));

        self::assertFalse($this->useCase->podeExcluir($mensagem, $this->autor, $this->tenant, $expirado));
    }

    public function testPodeExcluirRetornaTrueDentroDaJanela(): void
    {
        $mensagem = $this->novaMensagem();
        $dentro   = $mensagem->getCriadaEm()->add(new \DateInterval('PT23H'));

        self::assertTrue($this->useCase->podeExcluir($mensagem, $this->autor, $this->tenant, $dentro));
    }
}
