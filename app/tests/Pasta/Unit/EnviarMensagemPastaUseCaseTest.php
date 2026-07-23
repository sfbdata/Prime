<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Entity\Tenant\Tenant;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use App\Pasta\UseCase\EnviarMensagemPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnviarMensagemPastaUseCase::class)]
final class EnviarMensagemPastaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private EntityManagerInterface&MockObject $em;
    private EnviarMensagemPastaUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new EnviarMensagemPastaUseCase($this->em, $this->criarSanitizadorTextoRico());

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = new Pasta();
    }

    public function testEnviarMensagemCriaEntidade(): void
    {
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(PastaMensagem::class));
        $this->em->expects($this->once())->method('flush');

        $mensagem = $this->useCase->executar($this->pasta, $this->autor, 'Mensagem de teste', $this->tenant);

        self::assertSame('Mensagem de teste', $mensagem->getConteudo());
        self::assertSame($this->pasta, $mensagem->getPasta());
        self::assertSame($this->autor, $mensagem->getAutor());
        self::assertSame($this->tenant, $mensagem->getTenant());
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
