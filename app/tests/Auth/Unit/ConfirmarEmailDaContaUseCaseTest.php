<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Event\EmailDaContaConfirmado;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\UseCase\ConfirmarEmailDaContaUseCase;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(ConfirmarEmailDaContaUseCase::class)]
final class ConfirmarEmailDaContaUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-19 10:00:00';

    private ConfirmacaoEmailRepository&MockObject $repository;
    private EventDispatcher $eventos;

    /** @var list<EmailDaContaConfirmado> */
    private array $despachados = [];

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ConfirmacaoEmailRepository::class);
        $this->eventos = new EventDispatcher();
        $this->eventos->addListener(EmailDaContaConfirmado::class, function (EmailDaContaConfirmado $evento): void {
            $this->despachados[] = $evento;
        });
    }

    private function useCase(): ConfirmarEmailDaContaUseCase
    {
        return new ConfirmarEmailDaContaUseCase(
            $this->repository,
            $this->eventos,
            new NullLogger(),
            new MockClock(new \DateTimeImmutable(self::AGORA)),
        );
    }

    private function confirmacao(string $expiraEm = '2026-10-20 10:00:00', bool $confirmado = false): ConfirmacaoEmail
    {
        $user = (new User())->setEmail('ana@adv.com')->setFullName('Ana Souza');
        (new \ReflectionProperty($user, 'id'))->setValue($user, 42);

        if (!$confirmado) {
            $user->marcarEmailAConfirmar(new \DateTimeImmutable('2026-10-18 10:00:00'));
        }

        return new ConfirmacaoEmail($user, ConfirmacaoEmail::hashDoToken('tok'), new \DateTimeImmutable($expiraEm));
    }

    #[TestDox('Confirma o e-mail, marca o link usado, grava e despacha EmailDaContaConfirmado depois')]
    public function testConfirma(): void
    {
        $confirmacao = $this->confirmacao();
        $this->repository->method('encontrarPorToken')->with('tok')->willReturn($confirmacao);
        $this->repository->expects($this->once())->method('salvar')->with($confirmacao, true);

        $this->useCase()->executar('tok');

        self::assertTrue($confirmacao->getUser()->isEmailConfirmado());
        self::assertEquals(new \DateTimeImmutable(self::AGORA), $confirmacao->getUsadoEm());
        self::assertCount(1, $this->despachados);
        self::assertSame(42, $this->despachados[0]->userId);
    }

    #[TestDox('Segundo clique (conta já confirmada) é sucesso sem gravar e sem novo evento')]
    public function testIdempotente(): void
    {
        $this->repository->method('encontrarPorToken')->willReturn($this->confirmacao(confirmado: true));
        $this->repository->expects($this->never())->method('salvar');

        $this->useCase()->executar('tok');

        self::assertSame([], $this->despachados);
    }

    #[TestDox('Token inexistente: link inválido ou expirado')]
    public function testTokenInexistente(): void
    {
        $this->repository->method('encontrarPorToken')->willReturn(null);
        $this->repository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Este link de confirmação é inválido ou expirou.');

        $this->useCase()->executar('tok');
    }

    #[TestDox('Link vencido não confirma: mesma mensagem, nada gravado, nenhum evento')]
    public function testVencido(): void
    {
        $confirmacao = $this->confirmacao(expiraEm: '2026-10-19 09:59:59');
        $this->repository->method('encontrarPorToken')->willReturn($confirmacao);
        $this->repository->expects($this->never())->method('salvar');

        try {
            $this->useCase()->executar('tok');
            self::fail('Esperava DomainException.');
        } catch (\DomainException $e) {
            self::assertSame(ConfirmarEmailDaContaUseCase::ERRO_LINK, $e->getMessage());
        }

        self::assertFalse($confirmacao->getUser()->isEmailConfirmado());
        self::assertSame([], $this->despachados);
    }

    #[TestDox('Ouvinte com defeito não vira erro: o e-mail já está confirmado no banco')]
    public function testOuvinteComDefeito(): void
    {
        $confirmacao = $this->confirmacao();
        $this->repository->method('encontrarPorToken')->willReturn($confirmacao);
        $this->eventos->addListener(EmailDaContaConfirmado::class, static function (): void {
            throw new \RuntimeException('ouvinte quebrado');
        });

        $this->useCase()->executar('tok');

        self::assertTrue($confirmacao->getUser()->isEmailConfirmado());
    }
}
