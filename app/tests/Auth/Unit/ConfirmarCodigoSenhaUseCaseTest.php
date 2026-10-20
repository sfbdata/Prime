<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\ConfirmarCodigoSenhaInput;
use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\Event\EmailDaContaConfirmado;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Auth\Service\ForcaDaSenha;
use App\Auth\UseCase\ConfirmarCodigoSenhaUseCase;
use App\Auth\UseCase\SolicitarCodigoSenhaUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * O EntityManager entra só pelo wrapInTransaction, executado direto aqui, no mesmo padrão
 * do RedefinirSenhaUseCaseTest. A transação de verdade é provada no teste funcional.
 */
#[CoversClass(ConfirmarCodigoSenhaUseCase::class)]
final class ConfirmarCodigoSenhaUseCaseTest extends TestCase
{
    private const SEGREDO = 'segredo-de-teste';
    private const CODIGO = '012345';
    private const SENHA = 'SenhaNova123';

    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $userRepository;
    private RedefinicaoSenhaRepository&MockObject $redefinicaoRepository;
    private UserPasswordHasherInterface&MockObject $hasher;
    private ConfirmacaoEmailRepository&MockObject $confirmacaoEmailRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private ConfirmarCodigoSenhaUseCase $useCase;

    /** Resultado do UPDATE condicional: false = outra requisição consumiu o código antes. */
    private bool $consumoAtomicoVence = true;

    /** O banco já tem o e-mail confirmado (o clique no link ganhou antes), mesmo com o objeto em memória atrasado. */
    private bool $bancoJaConfirmado = false;

    protected function setUp(): void
    {
        $this->em                    = $this->createMock(EntityManagerInterface::class);
        $this->userRepository        = $this->createMock(UserRepository::class);
        $this->redefinicaoRepository = $this->createMock(RedefinicaoSenhaRepository::class);
        $this->hasher                = $this->createMock(UserPasswordHasherInterface::class);
        $this->confirmacaoEmailRepository = $this->createMock(ConfirmacaoEmailRepository::class);
        $this->eventDispatcher       = $this->createMock(EventDispatcherInterface::class);
        $this->useCase               = new ConfirmarCodigoSenhaUseCase(
            $this->em,
            $this->userRepository,
            $this->redefinicaoRepository,
            $this->hasher,
            self::SEGREDO,
            $this->confirmacaoEmailRepository,
            $this->eventDispatcher,
            $this->createStub(LoggerInterface::class),
        );
        // Faz no objeto o que o UPDATE condicional faz no banco: só ganha quem encontra o e-mail ainda a confirmar.
        $this->confirmacaoEmailRepository->method('confirmarEmailDaConta')
            ->willReturnCallback(fn (User $u): bool => !$this->bancoJaConfirmado && !$u->isEmailConfirmado());

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $cb) => $cb());
        $this->redefinicaoRepository->method('consumir')
            ->willReturnCallback(fn (): bool => $this->consumoAtomicoVence);
        $this->hasher->method('hashPassword')->willReturn('HASH_NOVO');
        $this->redefinicaoRepository->method('reservarTentativa')->willReturnCallback($this->reservaNoBanco(...));
        $this->redefinicaoRepository->method('queimarSeEsgotado')->willReturnCallback($this->queimaNoBanco(...));
    }

    /** Faz no objeto o que o UPDATE condicional do repositório faz no banco. */
    private function reservaNoBanco(RedefinicaoSenha $pedido, int $maximo): bool
    {
        if ($pedido->isUsado() || $pedido->getTentativas() >= $maximo) {
            return false;
        }
        $pedido->registrarTentativaErrada();

        return true;
    }

    private function queimaNoBanco(RedefinicaoSenha $pedido, int $maximo, \DateTimeImmutable $agora): void
    {
        if ($pedido->getTentativas() >= $maximo) {
            $pedido->marcarUsado($agora);
        }
    }

    private function usuario(bool $ativo = true): User
    {
        $user = new User();
        $user->setEmail('ana@adv.com');
        $user->setPassword('HASH_ANTIGO');
        $user->setIsActive($ativo);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);

        return $user;
    }

    /** Conta encontrada pelo e-mail e com um pedido pendente para o CODIGO. */
    private function cenario(?User $user = null, string $expiraEm = '+15 minutes'): RedefinicaoSenha
    {
        $user ??= $this->usuario();
        $pedido = new RedefinicaoSenha(
            user: $user,
            tokenHash: SolicitarCodigoSenhaUseCase::hashDoCodigo(42, self::CODIGO, self::SEGREDO),
            expiraEm: new \DateTimeImmutable($expiraEm),
            ip: '1.2.3.4',
        );

        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$user]);
        $this->redefinicaoRepository->method('encontrarPendenteMaisNovoDoUsuario')->willReturn($pedido);

        return $pedido;
    }

    private function input(string $codigo = self::CODIGO, string $senha = self::SENHA, ?string $confirmacao = null): ConfirmarCodigoSenhaInput
    {
        return new ConfirmarCodigoSenhaInput('ana@adv.com', $codigo, $senha, $confirmacao ?? $senha);
    }

    private function esperarErro(string $mensagem): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($mensagem);
    }

    #[TestDox('Código certo: consome o pedido, grava a senha hasheada e invalida os outros pendentes')]
    public function testConfirmaComSucesso(): void
    {
        $pedido = $this->cenario();
        $this->redefinicaoRepository->expects($this->once())
            ->method('invalidarPendentesDoUsuario')
            ->with($pedido->getUser(), $pedido);
        $this->redefinicaoRepository->expects($this->once())->method('salvar')->with($pedido, true);

        $this->useCase->executar($this->input());

        self::assertSame('HASH_NOVO', $pedido->getUser()->getPassword());
        self::assertTrue($pedido->isUsado());
    }

    #[TestDox('Código certo confirma o e-mail da conta: o código chegou no endereço')]
    public function testCodigoCertoConfirmaOEmail(): void
    {
        $user = $this->usuario();
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 day'));
        $this->cenario($user);

        $this->useCase->executar($this->input());

        self::assertTrue($user->isEmailConfirmado());
        self::assertNull($user->getEmailAConfirmarDesde());
    }

    #[TestDox('Código certo com o e-mail a confirmar despacha EmailDaContaConfirmado uma vez, com o id da conta')]
    public function testCodigoCertoDespachaEmailConfirmado(): void
    {
        $user = $this->usuario();
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 day'));
        $this->cenario($user);
        $this->eventDispatcher->expects($this->once())->method('dispatch')
            ->with(self::callback(static fn (object $e): bool => $e instanceof EmailDaContaConfirmado && $e->userId === 42))
            ->willReturnArgument(0);

        $this->useCase->executar($this->input());
    }

    #[TestDox('Código certo com a conta já confirmada não despacha o evento')]
    public function testContaJaConfirmadaNaoDespacha(): void
    {
        $this->cenario();
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->useCase->executar($this->input());
    }

    #[TestDox('Confirmada pelo link antes do código (o banco já confirmado): troca a senha e não despacha de novo')]
    public function testConfirmadaPeloLinkAntesNaoDespachaDeNovo(): void
    {
        $user = $this->usuario();
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 day'));
        $this->cenario($user);
        $this->bancoJaConfirmado = true;
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->useCase->executar($this->input());

        self::assertSame('HASH_NOVO', $user->getPassword());
        self::assertTrue($user->isEmailConfirmado());
    }

    #[TestDox('Código errado não despacha o evento')]
    public function testCodigoErradoNaoDespacha(): void
    {
        $user = $this->usuario();
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 day'));
        $this->cenario($user);
        $this->eventDispatcher->expects($this->never())->method('dispatch');
        $this->esperarErro(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO);

        $this->useCase->executar($this->input('999999'));
    }

    #[TestDox('Código certo, mas outra requisição consumiu antes: não despacha o evento')]
    public function testCorridaPerdidaNaoDespacha(): void
    {
        $user = $this->usuario();
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 day'));
        $this->cenario($user);
        $this->consumoAtomicoVence = false;
        $this->eventDispatcher->expects($this->never())->method('dispatch');
        $this->esperarErro(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO);

        $this->useCase->executar($this->input());
    }

    #[TestDox('Código errado não confirma o e-mail')]
    public function testCodigoErradoNaoConfirmaOEmail(): void
    {
        $user = $this->usuario();
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 day'));
        $this->cenario($user);

        try {
            $this->useCase->executar($this->input('999999'));
            self::fail('Código errado não pode passar.');
        } catch (\DomainException) {
        }

        self::assertFalse($user->isEmailConfirmado());
    }

    #[TestDox('Código errado gasta a tentativa reservada no banco e devolve o erro neutro')]
    public function testCodigoErradoContaTentativa(): void
    {
        $pedido = $this->cenario();
        $this->hasher->expects($this->never())->method('hashPassword');

        try {
            $this->useCase->executar($this->input('999999'));
            self::fail('Código errado tinha que ser recusado.');
        } catch (\DomainException $e) {
            self::assertSame(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO, $e->getMessage());
        }

        self::assertSame(1, $pedido->getTentativas());
        self::assertFalse($pedido->isUsado());
    }

    #[TestDox('Na quinta tentativa errada o pedido queima e nem o código certo passa mais')]
    public function testCincoTentativasQueimamOPedido(): void
    {
        $pedido = $this->cenario();

        for ($i = 0; $i < ConfirmarCodigoSenhaUseCase::MAX_TENTATIVAS; ++$i) {
            try {
                $this->useCase->executar($this->input('999999'));
            } catch (\DomainException) {
                // Esperado: cada código errado é recusado com o erro neutro.
            }
        }

        self::assertTrue($pedido->isUsado(), 'Com 5 erros o pedido fica inválido.');

        $this->esperarErro(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO);
        $this->useCase->executar($this->input());
    }

    #[TestDox('Pedido com 5 tentativas não confere mais, mesmo sem marca de uso')]
    public function testPedidoComCincoTentativasNaoConfere(): void
    {
        $pedido = $this->cenario();
        for ($i = 0; $i < ConfirmarCodigoSenhaUseCase::MAX_TENTATIVAS; ++$i) {
            $pedido->registrarTentativaErrada();
        }

        $this->esperarErro(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO);
        $this->useCase->executar($this->input());
    }

    /** @return iterable<string, array{string}> */
    public static function codigosForaDoFormato(): iterable
    {
        yield 'cinco dígitos' => ['12345'];
        yield 'sete dígitos' => ['1234567'];
        yield 'com letra' => ['12a456'];
        yield 'vazio' => [''];
    }

    #[DataProvider('codigosForaDoFormato')]
    #[TestDox('Código fora do formato devolve o erro neutro sem gastar tentativa')]
    public function testFormatoErradoNaoGastaTentativa(string $codigo): void
    {
        $pedido = $this->cenario();
        $this->redefinicaoRepository->expects($this->never())->method('reservarTentativa');

        try {
            $this->useCase->executar($this->input($codigo));
            self::fail('Formato errado tinha que ser recusado.');
        } catch (\DomainException $e) {
            self::assertSame(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO, $e->getMessage());
        }

        self::assertSame(0, $pedido->getTentativas());
    }

    #[TestDox('Conta inexistente dá o mesmo erro neutro do código errado (RN02)')]
    public function testContaInexistente(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([]);
        $this->redefinicaoRepository->expects($this->never())->method('reservarTentativa');

        $this->esperarErro(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO);
        $this->useCase->executar($this->input());
    }

    #[TestDox('Conta desativada depois do pedido dá o erro neutro')]
    public function testContaInativa(): void
    {
        $this->cenario($this->usuario(ativo: false));
        $this->redefinicaoRepository->expects($this->never())->method('reservarTentativa');

        $this->esperarErro(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO);
        $this->useCase->executar($this->input());
    }

    #[TestDox('Sem pedido pendente dá o erro neutro')]
    public function testSemPedidoPendente(): void
    {
        $this->userRepository->method('encontrarPorEmailIgnorandoCaixa')->willReturn([$this->usuario()]);
        $this->redefinicaoRepository->method('encontrarPendenteMaisNovoDoUsuario')->willReturn(null);

        $this->esperarErro(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO);
        $this->useCase->executar($this->input());
    }

    #[TestDox('Código vencido (mais de 15 minutos) dá o erro neutro')]
    public function testCodigoVencido(): void
    {
        $this->cenario(expiraEm: '-1 minute');
        $this->hasher->expects($this->never())->method('hashPassword');

        $this->esperarErro(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO);
        $this->useCase->executar($this->input());
    }

    #[TestDox('Senha fora da régua: erro da ForcaDaSenha, sem consumir o código')]
    public function testSenhaFraca(): void
    {
        $pedido = $this->cenario();
        $this->redefinicaoRepository->expects($this->never())->method('consumir');

        try {
            $this->useCase->executar($this->input(senha: 'abcdefgh'));
            self::fail('Senha fraca tinha que ser recusada.');
        } catch (\DomainException $e) {
            self::assertSame(ForcaDaSenha::ERRO, $e->getMessage());
        }

        self::assertFalse($pedido->isUsado(), 'Corrigindo a senha, o mesmo código ainda serve.');
        self::assertSame(0, $pedido->getTentativas());
    }

    #[TestDox('Confirmação diferente: "As senhas não conferem."')]
    public function testConfirmacaoDiferente(): void
    {
        $this->cenario();
        $this->redefinicaoRepository->expects($this->never())->method('consumir');

        $this->esperarErro('As senhas não conferem.');
        $this->useCase->executar($this->input(confirmacao: 'OutraSenha123'));
    }

    #[TestDox('Senha fraca é recusada antes de olhar o código, sem gastar tentativa')]
    public function testSenhaFracaAntesDoCodigo(): void
    {
        $pedido = $this->cenario();

        try {
            $this->useCase->executar($this->input('999999', 'fraca'));
            self::fail('Senha fraca tinha que ser recusada.');
        } catch (\DomainException $e) {
            self::assertSame(ForcaDaSenha::ERRO, $e->getMessage());
        }

        self::assertSame(0, $pedido->getTentativas());
    }

    #[TestDox('Sem vaga no banco (outra requisição levou a última), nem compara o código')]
    public function testSemVagaNaoCompara(): void
    {
        $pedido = $this->cenario();
        for ($i = 0; $i < ConfirmarCodigoSenhaUseCase::MAX_TENTATIVAS - 1; ++$i) {
            $pedido->registrarTentativaErrada();
        }
        $this->reservaNoBanco($pedido, ConfirmarCodigoSenhaUseCase::MAX_TENTATIVAS);
        $this->hasher->expects($this->never())->method('hashPassword');

        $this->esperarErro(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO);
        $this->useCase->executar($this->input());
    }

    #[TestDox('Senha de mais de 4096 bytes com acento é recusada pela régua, não vira 500 no hasher')]
    public function testSenhaLongaEmBytes(): void
    {
        $this->cenario();

        $this->esperarErro(ForcaDaSenha::ERRO);
        $this->useCase->executar($this->input(senha: str_repeat('ç', 2100) . 'A1'));
    }

    #[TestDox('Corrida: quem perde o UPDATE condicional não troca a senha')]
    public function testCorridaNoConsumo(): void
    {
        $this->consumoAtomicoVence = false;
        $pedido = $this->cenario();
        $this->hasher->expects($this->never())->method('hashPassword');

        try {
            $this->useCase->executar($this->input());
            self::fail('Quem perde a corrida não pode trocar a senha.');
        } catch (\DomainException $e) {
            self::assertSame(ConfirmarCodigoSenhaUseCase::ERRO_CODIGO, $e->getMessage());
        }

        self::assertSame('HASH_ANTIGO', $pedido->getUser()->getPassword());
    }
}
