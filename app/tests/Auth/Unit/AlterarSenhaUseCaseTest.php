<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Auth\UseCase\AlterarSenhaUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(AlterarSenhaUseCase::class)]
final class AlterarSenhaUseCaseTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private RedefinicaoSenhaRepository&MockObject $redefinicaoRepository;
    private UserPasswordHasherInterface&MockObject $hasher;
    private AlterarSenhaUseCase $useCase;
    private RateLimiterFactory $limitador;

    protected function setUp(): void
    {
        $this->userRepository        = $this->createMock(UserRepository::class);
        $this->redefinicaoRepository = $this->createMock(RedefinicaoSenhaRepository::class);
        $this->hasher                = $this->createMock(UserPasswordHasherInterface::class);
        $this->limitador             = new RateLimiterFactory(
            ['id' => 'perfil_senha_atual', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );
        $this->useCase               = new AlterarSenhaUseCase(
            $this->userRepository,
            $this->redefinicaoRepository,
            $this->hasher,
            $this->limitador,
        );
    }

    private function usuario(): User
    {
        $user = new User();
        $user->setEmail('ana@adv.com');
        $user->setPassword('HASH_ANTIGO');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 7);

        return $user;
    }

    #[TestDox('Com o limitador da conta esgotado, a senha atual nem é conferida (auditoria 3.27)')]
    public function testLimiteEsgotadoNaoConfereASenha(): void
    {
        $user = $this->usuario();
        $this->limitador->create('7')->consume(5);
        $this->hasher->expects($this->never())->method('isPasswordValid');
        $this->userRepository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(AlterarSenhaUseCase::MUITAS_TENTATIVAS);

        $this->useCase->executar($user, 'senha-atual', 'senha-nova-123');
    }

    #[TestDox('O limite é por conta: o esgotado de uma conta não afeta outra')]
    public function testLimiteEPorConta(): void
    {
        $this->limitador->create('99')->consume(5);
        $this->hasher->method('isPasswordValid')->willReturn(true);
        $this->hasher->method('hashPassword')->willReturn('HASH_NOVO');
        $user = $this->usuario();

        $this->useCase->executar($user, 'senha-atual', 'senha-nova-123');

        self::assertSame('HASH_NOVO', $user->getPassword());
    }

    #[TestDox('Troca a senha quando a senha atual confere')]
    public function testTrocaComSucesso(): void
    {
        $user = $this->usuario();
        $this->hasher->method('isPasswordValid')->willReturn(true);
        $this->hasher->method('hashPassword')->willReturn('HASH_NOVO');
        $this->userRepository->expects($this->once())->method('salvar')->with($user, true);

        $this->useCase->executar($user, 'senha-atual', 'senha-nova-123');

        self::assertSame('HASH_NOVO', $user->getPassword());
    }

    #[TestDox('Trocar a senha mata os links de redefinição pendentes')]
    public function testInvalidaLinksPendentes(): void
    {
        // Sem isto: quem teve acesso momentâneo ao e-mail pede um link e guarda; a vítima
        // percebe e troca a senha pelo perfil; o link guardado ainda reseta a senha depois.
        $user = $this->usuario();
        $this->hasher->method('isPasswordValid')->willReturn(true);
        $this->hasher->method('hashPassword')->willReturn('HASH_NOVO');

        $this->redefinicaoRepository->expects($this->once())
            ->method('invalidarPendentesDoUsuario')
            ->with($user);

        $this->useCase->executar($user, 'senha-atual', 'senha-nova-123');
    }

    #[TestDox('Senha atual incorreta não mexe nos links pendentes')]
    public function testSenhaAtualIncorretaNaoInvalidaLinks(): void
    {
        $this->hasher->method('isPasswordValid')->willReturn(false);
        $this->redefinicaoRepository->expects($this->never())->method('invalidarPendentesDoUsuario');

        $this->expectException(\DomainException::class);
        $this->useCase->executar($this->usuario(), 'chute-errado', 'senha-nova-123');
    }

    #[TestDox('Senha atual incorreta bloqueia a troca')]
    public function testSenhaAtualIncorreta(): void
    {
        $user = $this->usuario();
        $this->hasher->method('isPasswordValid')->willReturn(false);
        $this->userRepository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Senha atual incorreta');

        try {
            $this->useCase->executar($user, 'chute-errado', 'senha-nova-123');
        } finally {
            self::assertSame('HASH_ANTIGO', $user->getPassword(), 'A senha não pode mudar quando a atual está errada.');
        }
    }

    #[TestDox('Repetir a mesma senha é recusado')]
    public function testNovaSenhaIgualAAtual(): void
    {
        $user = $this->usuario();
        $this->hasher->method('isPasswordValid')->willReturn(true);
        $this->userRepository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('diferente da atual');
        $this->useCase->executar($user, 'mesma-senha-123', 'mesma-senha-123');
    }
}
