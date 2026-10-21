<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\CriarSenhaPeloLinkInput;
use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Enum\SituacaoDoLinkDeConfirmacao;
use App\Auth\Event\EmailDaContaConfirmado;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\Service\ForcaDaSenha;
use App\Auth\Service\LinkDeConfirmacaoDeEmail;
use App\Auth\UseCase\CriarSenhaPeloLinkUseCase;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;

/**
 * Sem banco: o repositório é o nosso (mockado); o UPDATE condicional dele, a corrida de verdade, está no
 * CriarSenhaPeloLinkCorridaTest e no CadastroControllerTest.
 */
#[CoversClass(CriarSenhaPeloLinkUseCase::class)]
#[CoversClass(LinkDeConfirmacaoDeEmail::class)]
final class CriarSenhaPeloLinkUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-21 10:00:00';
    private const SENHA_ANTIGA = 'hash-da-senha-do-cadastro';
    private const SENHA = 'novaSenha456';

    private ConfirmacaoEmailRepository&MockObject $repository;
    private EventDispatcher $eventos;
    private UserPasswordHasher $hasher;

    /** @var list<EmailDaContaConfirmado> */
    private array $despachados = [];

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ConfirmacaoEmailRepository::class);
        $this->eventos = new EventDispatcher();
        $this->eventos->addListener(EmailDaContaConfirmado::class, function (EmailDaContaConfirmado $evento): void {
            $this->despachados[] = $evento;
        });
        $this->hasher = new UserPasswordHasher(new PasswordHasherFactory([
            User::class => ['algorithm' => 'pbkdf2', 'iterations' => 1],
        ]));
    }

    private function useCase(): CriarSenhaPeloLinkUseCase
    {
        $relogio = new MockClock(new \DateTimeImmutable(self::AGORA));

        return new CriarSenhaPeloLinkUseCase(
            new LinkDeConfirmacaoDeEmail($this->repository, $relogio),
            $this->repository,
            $this->hasher,
            $this->eventos,
            new NullLogger(),
            $relogio,
        );
    }

    private function confirmacao(string $expiraEm = '2026-10-25 10:00:00', bool $confirmado = false): ConfirmacaoEmail
    {
        $user = (new User())->setEmail('ana@adv.com')->setFullName('Ana Souza')->setPassword(self::SENHA_ANTIGA);
        (new \ReflectionProperty($user, 'id'))->setValue($user, 42);

        if (!$confirmado) {
            $user->marcarEmailAConfirmar(new \DateTimeImmutable('2026-10-20 10:00:00'));
        }

        return new ConfirmacaoEmail($user, ConfirmacaoEmail::hashDoToken('tok'), new \DateTimeImmutable($expiraEm));
    }

    private static function input(string $senha = self::SENHA, ?string $confirmacao = null): CriarSenhaPeloLinkInput
    {
        return new CriarSenhaPeloLinkInput('tok', $senha, $confirmacao ?? $senha);
    }

    #[TestDox('Token válido: grava o hash da senha nova na mesma chamada que confirma, marca o link e despacha o evento uma vez')]
    public function testCriaASenhaEConfirma(): void
    {
        $confirmacao = $this->confirmacao();
        $this->repository->method('encontrarPorToken')->with('tok')->willReturn($confirmacao);
        $hashGravado = null;
        $this->repository->expects($this->once())->method('criarSenhaEConfirmarComLink')
            ->willReturnCallback(static function (ConfirmacaoEmail $c, string $hash, \DateTimeImmutable $agora) use ($confirmacao, &$hashGravado): bool {
                self::assertSame($confirmacao, $c);
                self::assertEquals(new \DateTimeImmutable(self::AGORA), $agora);
                $hashGravado = $hash;

                return true;
            });

        $situacao = $this->useCase()->executar(self::input());

        self::assertSame(SituacaoDoLinkDeConfirmacao::SenhaCriada, $situacao);
        $user = $confirmacao->getUser();
        self::assertNotSame(self::SENHA, $hashGravado);
        self::assertSame($hashGravado, $user->getPassword());
        self::assertTrue($this->hasher->isPasswordValid($user, self::SENHA));
        self::assertTrue($user->isEmailConfirmado());
        self::assertEquals(new \DateTimeImmutable(self::AGORA), $confirmacao->getUsadoEm());
        self::assertCount(1, $this->despachados);
        self::assertSame(42, $this->despachados[0]->userId);
    }

    #[TestDox('Token desconhecido: link inválido ou expirado, nada gravado')]
    public function testTokenInexistente(): void
    {
        $this->repository->method('encontrarPorToken')->willReturn(null);
        $this->repository->expects($this->never())->method('criarSenhaEConfirmarComLink');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Este link de confirmação é inválido ou expirou.');

        $this->useCase()->executar(self::input());
    }

    #[TestDox('Link vencido: a mesma mensagem, a senha antiga fica, nenhum evento')]
    public function testVencido(): void
    {
        $confirmacao = $this->confirmacao(expiraEm: '2026-10-21 09:59:59');
        $this->repository->method('encontrarPorToken')->willReturn($confirmacao);
        $this->repository->expects($this->never())->method('criarSenhaEConfirmarComLink');

        try {
            $this->useCase()->executar(self::input());
            self::fail('Esperava DomainException.');
        } catch (\DomainException $e) {
            self::assertSame(LinkDeConfirmacaoDeEmail::ERRO_LINK, $e->getMessage());
        }

        self::assertSame(self::SENHA_ANTIGA, $confirmacao->getUser()->getPassword());
        self::assertFalse($confirmacao->getUser()->isEmailConfirmado());
        self::assertSame([], $this->despachados);
    }

    #[TestDox('Conta já confirmada (mesmo com o link vencido e a senha fraca): "já confirmado", sem trocar a senha nem despachar')]
    public function testJaConfirmadoNaoTrocaASenha(): void
    {
        $confirmacao = $this->confirmacao(expiraEm: '2026-10-01 10:00:00', confirmado: true);
        $this->repository->method('encontrarPorToken')->willReturn($confirmacao);
        $this->repository->expects($this->never())->method('criarSenhaEConfirmarComLink');

        $situacao = $this->useCase()->executar(self::input('fraca'));

        self::assertSame(SituacaoDoLinkDeConfirmacao::JaConfirmado, $situacao);
        self::assertSame(self::SENHA_ANTIGA, $confirmacao->getUser()->getPassword());
        self::assertSame([], $this->despachados);
    }

    /** @return iterable<string, array{CriarSenhaPeloLinkInput, string}> */
    public static function senhasRecusadas(): iterable
    {
        yield 'só letras minúsculas' => [self::input('abcdefgh'), ForcaDaSenha::ERRO];
        yield 'curta' => [self::input('Ab1!'), ForcaDaSenha::ERRO];
        yield 'vazia' => [self::input(''), ForcaDaSenha::ERRO];
        yield 'acima do máximo do hasher' => [self::input('Ab1' . str_repeat('x', ForcaDaSenha::MAXIMO_EM_BYTES - 2)), ForcaDaSenha::ERRO];
        yield 'confirmação diferente' => [self::input(self::SENHA, 'novaSenha457'), 'As senhas não conferem.'];
        yield 'régua vem antes da confirmação' => [self::input('fraca', 'outra'), ForcaDaSenha::ERRO];
    }

    #[DataProvider('senhasRecusadas')]
    #[TestDox('Senha recusada ($mensagem) volta ao formulário sem consumir o link nem confirmar')]
    public function testSenhaRecusadaNaoConsomeOLink(CriarSenhaPeloLinkInput $input, string $mensagem): void
    {
        $confirmacao = $this->confirmacao();
        $this->repository->method('encontrarPorToken')->willReturn($confirmacao);
        $this->repository->expects($this->never())->method('criarSenhaEConfirmarComLink');

        try {
            $this->useCase()->executar($input);
            self::fail('Esperava InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame($mensagem, $e->getMessage());
        }

        self::assertNull($confirmacao->getUsadoEm());
        self::assertFalse($confirmacao->getUser()->isEmailConfirmado());
        self::assertSame(self::SENHA_ANTIGA, $confirmacao->getUser()->getPassword());
        self::assertSame([], $this->despachados);
    }

    #[TestDox('Quem perde a corrida do UPDATE recebe "já confirmado": não troca a senha em memória nem despacha de novo')]
    public function testCorridaPerdida(): void
    {
        $confirmacao = $this->confirmacao();
        $this->repository->method('encontrarPorToken')->willReturn($confirmacao);
        $this->repository->expects($this->once())->method('criarSenhaEConfirmarComLink')->willReturn(false);

        $situacao = $this->useCase()->executar(self::input());

        self::assertSame(SituacaoDoLinkDeConfirmacao::JaConfirmado, $situacao);
        self::assertSame(self::SENHA_ANTIGA, $confirmacao->getUser()->getPassword());
        self::assertNull($confirmacao->getUsadoEm());
        self::assertSame([], $this->despachados);
    }

    #[TestDox('Ouvinte com defeito não vira erro: a senha e o e-mail já estão gravados')]
    public function testOuvinteComDefeito(): void
    {
        $confirmacao = $this->confirmacao();
        $this->repository->method('encontrarPorToken')->willReturn($confirmacao);
        $this->repository->method('criarSenhaEConfirmarComLink')->willReturn(true);
        $this->eventos->addListener(EmailDaContaConfirmado::class, static function (): void {
            throw new \RuntimeException('ouvinte quebrado');
        });

        self::assertSame(SituacaoDoLinkDeConfirmacao::SenhaCriada, $this->useCase()->executar(self::input()));
        self::assertTrue($confirmacao->getUser()->isEmailConfirmado());
    }

    #[TestDox('A página do link só olha: pendente, já confirmado, ou o erro do link; nunca grava')]
    public function testSituacaoDoLinkNaoGrava(): void
    {
        $relogio = new MockClock(new \DateTimeImmutable(self::AGORA));
        $link = new LinkDeConfirmacaoDeEmail($this->repository, $relogio);
        $this->repository->expects($this->never())->method('criarSenhaEConfirmarComLink');
        $this->repository->method('encontrarPorToken')->willReturnOnConsecutiveCalls(
            $this->confirmacao(),
            $this->confirmacao(expiraEm: '2026-10-01 10:00:00', confirmado: true),
            $this->confirmacao(expiraEm: '2026-10-01 10:00:00'),
        );

        self::assertSame(SituacaoDoLinkDeConfirmacao::Pendente, $link->situacao('tok'));
        self::assertSame(SituacaoDoLinkDeConfirmacao::JaConfirmado, $link->situacao('tok'));
        $this->expectExceptionObject(new \DomainException(LinkDeConfirmacaoDeEmail::ERRO_LINK));
        $link->situacao('tok');
    }
}
