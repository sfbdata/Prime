<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Service\ContaAConfirmar;
use App\Entity\Auth\User;
use App\Security\UserChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Conta com o e-mail "a confirmar" não entra (spec `cadastro-exige-confirmacao.md`): a recusa vem depois da senha
 * conferida (`checkPostAuth`), com a mensagem e o id da conta na sessão para o reenvio público.
 */
#[CoversClass(UserChecker::class)]
#[CoversClass(ContaAConfirmar::class)]
final class UserCheckerTest extends TestCase
{
    private Session $sessao;
    private RequestStack $pilha;
    private MockClock $relogio;
    private ContaAConfirmar $contaAConfirmar;

    protected function setUp(): void
    {
        $this->sessao = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->sessao);
        $this->pilha = new RequestStack();
        $this->pilha->push($request);
        $this->relogio = new MockClock(new \DateTimeImmutable('2026-10-22 10:00:00'));
        $this->contaAConfirmar = new ContaAConfirmar($this->relogio);
    }

    private function checker(?RequestStack $pilha = null): UserChecker
    {
        return new UserChecker($pilha ?? $this->pilha, $this->contaAConfirmar);
    }

    private static function conta(bool $aConfirmar, int $id = 42): User
    {
        $user = (new User())->setEmail('ana@adv.com')->setFullName('Ana Souza')->setIsActive(true);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        if ($aConfirmar) {
            $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        }

        return $user;
    }

    #[TestDox('E-mail a confirmar: recusa depois da senha, com a mensagem e o e-mail da conta, e guarda o id na sessão')]
    public function testContaAConfirmarERecusadaNoPostAuth(): void
    {
        $checker = $this->checker();

        try {
            $checker->checkPostAuth(self::conta(aConfirmar: true));
            self::fail('A conta a confirmar não pode entrar.');
        } catch (CustomUserMessageAccountStatusException $e) {
            self::assertSame(ContaAConfirmar::MENSAGEM_LOGIN, $e->getMessageKey());
            self::assertSame(['{email}' => 'ana@adv.com'], $e->getMessageData());
            self::assertSame('Confirme o seu e-mail para entrar. Enviamos o link para ana@adv.com.', strtr($e->getMessageKey(), $e->getMessageData()));
        }

        self::assertSame(42, $this->contaAConfirmar->idNaSessao($this->sessao));
    }

    #[TestDox('E-mail a confirmar não é recusado ANTES da senha: o checkPreAuth não revela a conta')]
    public function testPreAuthNaoRecusaContaAConfirmar(): void
    {
        ($this->checker())->checkPreAuth(self::conta(aConfirmar: true));

        self::assertNull($this->contaAConfirmar->idNaSessao($this->sessao));
    }

    #[TestDox('E-mail confirmado: entra e a sessão não ganha a chave')]
    public function testContaConfirmadaEntra(): void
    {
        ($this->checker())->checkPostAuth(self::conta(aConfirmar: false));

        self::assertNull($this->contaAConfirmar->idNaSessao($this->sessao));
    }

    #[TestDox('Sem pedido na pilha (linha de comando): recusa igual, sem sessão para gravar')]
    public function testSemPedidoRecusaIgual(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);

        ($this->checker(new RequestStack()))->checkPostAuth(self::conta(aConfirmar: true));
    }

    #[TestDox('Conta inativa continua recusada antes da senha')]
    public function testContaInativa(): void
    {
        $this->expectException(DisabledException::class);

        ($this->checker())->checkPreAuth(self::conta(aConfirmar: false)->setIsActive(false));
    }

    #[TestDox('Usuário que não é a entidade User passa sem verificação')]
    public function testOutroTipoDeUsuarioPassa(): void
    {
        $checker = $this->checker();
        $checker->checkPreAuth(new InMemoryUser('x', 'y'));
        $checker->checkPostAuth(new InMemoryUser('x', 'y'));

        self::assertNull($this->contaAConfirmar->idNaSessao($this->sessao));
    }

    #[TestDox('A chave vale 30 minutos: no limite ainda vale; passado dele, some da sessão')]
    public function testChaveVenceEmTrintaMinutos(): void
    {
        $this->contaAConfirmar->guardar($this->sessao, self::conta(aConfirmar: true));

        $this->relogio->sleep(30 * 60);
        self::assertSame(42, $this->contaAConfirmar->idNaSessao($this->sessao));

        $this->relogio->sleep(1);
        self::assertNull($this->contaAConfirmar->idNaSessao($this->sessao));
        self::assertFalse($this->sessao->has(ContaAConfirmar::CHAVE_SESSAO));
    }

    #[TestDox('Chave fora do formato (sem o momento) não vale e sai da sessão')]
    public function testChaveForaDoFormato(): void
    {
        $this->sessao->set(ContaAConfirmar::CHAVE_SESSAO, 42);

        self::assertNull($this->contaAConfirmar->idNaSessao($this->sessao));
        self::assertFalse($this->sessao->has(ContaAConfirmar::CHAVE_SESSAO));
    }
}
