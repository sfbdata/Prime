<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\MensagemPastaNaoEditavelException;
use App\Pasta\UseCase\EditarMensagemPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarMensagemPastaUseCase::class)]
final class EditarMensagemPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EditarMensagemPastaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EditarMensagemPastaUseCase($this->em);
        $this->tenant  = new Tenant();
        $this->autor   = (new User())->setEmail('autor@test.com');
    }

    private function novaMensagem(string $conteudo = 'Original'): PastaMensagem
    {
        return (new PastaMensagem())
            ->setPasta(new Pasta())
            ->setAutor($this->autor)
            ->setTenant($this->tenant)
            ->setConteudo($conteudo);
    }

    public function testAutorDentroDaJanelaEdita(): void
    {
        $mensagem = $this->novaMensagem();
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, 'Corrigido');

        self::assertSame('Corrigido', $mensagem->getConteudo());
        self::assertNotNull($mensagem->getEditadaEm());
    }

    public function testNaoAutorLancaExcecao(): void
    {
        $mensagem = $this->novaMensagem();
        $outro    = (new User())->setEmail('outro@test.com');

        $this->em->expects($this->never())->method('flush');

        $this->expectException(MensagemPastaNaoEditavelException::class);

        $this->useCase->executar($mensagem, $outro, $this->tenant, 'Corrigido');
    }

    public function testTenantDiferenteLancaExcecao(): void
    {
        $mensagem = $this->novaMensagem();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(MensagemPastaNaoEditavelException::class);

        $this->useCase->executar($mensagem, $this->autor, new Tenant(), 'Corrigido');
    }

    public function testConteudoVazioLancaInvalidArgument(): void
    {
        $mensagem = $this->novaMensagem();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, '   ');
    }

    public function testConteudoAcimaDe5000LancaInvalidArgument(): void
    {
        $mensagem = $this->novaMensagem();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, str_repeat('a', 5001));
    }

    public function testPodeEditarRetornaFalseForaDaJanela(): void
    {
        $mensagem = $this->novaMensagem();
        $expirado = $mensagem->getCriadaEm()->add(new \DateInterval('PT25H'));

        self::assertFalse($this->useCase->podeEditar($mensagem, $this->autor, $this->tenant, $expirado));
    }

    public function testPodeEditarRetornaTrueDentroDaJanela(): void
    {
        $mensagem = $this->novaMensagem();
        $dentro   = $mensagem->getCriadaEm()->add(new \DateInterval('PT23H'));

        self::assertTrue($this->useCase->podeEditar($mensagem, $this->autor, $this->tenant, $dentro));
    }
}
