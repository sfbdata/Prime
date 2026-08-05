<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Auth\UseCase\RedefinirSenhaUseCase;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(RedefinirSenhaUseCase::class)]
final class RedefinirSenhaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RedefinicaoSenhaRepository&MockObject $redefinicaoRepository;
    private UserPasswordHasherInterface&MockObject $hasher;
    private RedefinirSenhaUseCase $useCase;

    /** Resultado do UPDATE condicional: false = outra requisição consumiu o token antes. */
    private bool $consumoAtomicoVence = true;

    protected function setUp(): void
    {
        $this->em                    = $this->createMock(EntityManagerInterface::class);
        $this->redefinicaoRepository = $this->createMock(RedefinicaoSenhaRepository::class);
        $this->hasher                = $this->createMock(UserPasswordHasherInterface::class);
        $this->useCase               = new RedefinirSenhaUseCase(
            $this->em,
            $this->redefinicaoRepository,
            $this->hasher,
        );

        // Executa a closure da transação diretamente.
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $cb) => $cb());

        // Padrão: o consumo atômico dá certo. O teste da corrida vira a chave para false.
        $this->redefinicaoRepository->method('consumir')
            ->willReturnCallback(fn (): bool => $this->consumoAtomicoVence);
    }

    private function usuario(bool $ativo = true): User
    {
        $user = new User();
        $user->setEmail('ana@adv.com');
        $user->setPassword('HASH_ANTIGO');
        $user->setIsActive($ativo);

        return $user;
    }

    private function pedido(?User $user = null, string $expiraEm = '+1 hour'): RedefinicaoSenha
    {
        return new RedefinicaoSenha(
            user: $user ?? $this->usuario(),
            tokenHash: RedefinicaoSenha::hashDoToken('token-em-claro'),
            expiraEm: new \DateTimeImmutable($expiraEm),
            ip: '1.2.3.4',
        );
    }

    #[TestDox('Grava a senha nova e consome o token')]
    public function testRedefineComSucesso(): void
    {
        $pedido = $this->pedido();
        $this->redefinicaoRepository->method('encontrarPorToken')->willReturn($pedido);
        $this->hasher->method('hashPassword')->willReturn('HASH_NOVO');
        $this->redefinicaoRepository->expects($this->once())->method('salvar')->with($pedido, true);

        $user = $this->useCase->executar('token-em-claro', 'senha-nova-123');

        self::assertSame('HASH_NOVO', $user->getPassword());
        self::assertTrue($pedido->isUsado(), 'O token precisa ficar marcado como usado.');
    }

    #[TestDox('Preserva o próprio pedido ao invalidar os demais do usuário')]
    public function testNaoApagaOProprioPedidoAoInvalidarOsOutros(): void
    {
        $pedido = $this->pedido();
        $this->redefinicaoRepository->method('encontrarPorToken')->willReturn($pedido);
        $this->hasher->method('hashPassword')->willReturn('HASH_NOVO');

        // Defesa em profundidade: o consumo atômico já grava `usado_em`, então o
        // `WHERE usado_em IS NULL` sozinho preservaria a linha. O `exceto` garante que o
        // chamador não dependa dessa ordem para não apagar o próprio pedido.
        $this->redefinicaoRepository->expects($this->once())
            ->method('invalidarPendentesDoUsuario')
            ->with($pedido->getUser(), $pedido);

        $this->useCase->executar('token-em-claro', 'senha-nova-123');
    }

    #[TestDox('Token inexistente é recusado')]
    public function testTokenInexistente(): void
    {
        $this->redefinicaoRepository->method('encontrarPorToken')->willReturn(null);
        $this->redefinicaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->useCase->executar('token-que-nao-existe', 'senha-nova-123');
    }

    #[TestDox('Token já usado é recusado (uso único)')]
    public function testTokenJaUsado(): void
    {
        $pedido = $this->pedido();
        $pedido->marcarUsado();
        $this->redefinicaoRepository->method('encontrarPorToken')->willReturn($pedido);
        $this->redefinicaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('já foi usado');
        $this->useCase->executar('token-em-claro', 'senha-nova-123');
    }

    #[TestDox('Token expirado é recusado')]
    public function testTokenExpirado(): void
    {
        $this->redefinicaoRepository->method('encontrarPorToken')->willReturn($this->pedido(expiraEm: '-1 minute'));
        $this->redefinicaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('expirou');
        $this->useCase->executar('token-em-claro', 'senha-nova-123');
    }

    #[TestDox('Conta desativada entre o pedido e o clique não redefine')]
    public function testUsuarioInativado(): void
    {
        $this->redefinicaoRepository->method('encontrarPorToken')->willReturn($this->pedido($this->usuario(ativo: false)));
        $this->redefinicaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('desativada');
        $this->useCase->executar('token-em-claro', 'senha-nova-123');
    }

    #[TestDox('Corrida: quem perde o UPDATE condicional não troca a senha')]
    public function testCorridaNoConsumoDoToken(): void
    {
        // Duas requisições com o mesmo token passam juntas pela validação em PHP;
        // quem decide é o `WHERE usado_em IS NULL` do banco.
        $this->consumoAtomicoVence = false;

        $pedido = $this->pedido();
        $this->redefinicaoRepository->method('encontrarPorToken')->willReturn($pedido);
        $this->redefinicaoRepository->expects($this->never())->method('salvar');
        $this->hasher->expects($this->never())->method('hashPassword');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('já foi usado');

        try {
            $this->useCase->executar('token-em-claro', 'senha-nova-123');
        } finally {
            self::assertSame('HASH_ANTIGO', $pedido->getUser()->getPassword(), 'Perder a corrida não pode mudar a senha.');
        }
    }

    #[TestDox('A senha nova é hasheada — nunca vai em claro para a entidade')]
    public function testSenhaNuncaVaiEmClaro(): void
    {
        $pedido = $this->pedido();
        $this->redefinicaoRepository->method('encontrarPorToken')->willReturn($pedido);
        $this->hasher->expects($this->once())
            ->method('hashPassword')
            ->with($pedido->getUser(), 'senha-nova-123')
            ->willReturn('HASH_NOVO');

        $user = $this->useCase->executar('token-em-claro', 'senha-nova-123');

        self::assertNotSame('senha-nova-123', $user->getPassword());
    }
}
