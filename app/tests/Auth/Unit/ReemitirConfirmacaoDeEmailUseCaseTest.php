<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Entity\ConfirmacaoEmail;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\UseCase\ReemitirConfirmacaoDeEmailUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[CoversClass(ReemitirConfirmacaoDeEmailUseCase::class)]
final class ReemitirConfirmacaoDeEmailUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-19 10:00:00';

    private UserRepository&MockObject $userRepository;
    private ConfirmacaoEmailRepository&MockObject $confirmacaoRepository;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->confirmacaoRepository = $this->createMock(ConfirmacaoEmailRepository::class);
    }

    private function useCase(): ReemitirConfirmacaoDeEmailUseCase
    {
        $rotas = new RouteCollection();
        $rotas->add('auth_cadastro_confirmar_email', new Route('/cadastro/confirmar-email/{token}'));

        return new ReemitirConfirmacaoDeEmailUseCase(
            $this->userRepository,
            $this->confirmacaoRepository,
            new UrlGenerator($rotas, new RequestContext(host: 'bluejus.test', scheme: 'https')),
            new MockClock(new \DateTimeImmutable(self::AGORA)),
        );
    }

    private static function pendente(): User
    {
        $user = (new User())->setEmail('ana@adv.com')->setFullName('Ana Souza');
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('2026-10-16 10:00:00'));

        return $user;
    }

    #[TestDox('Cria o link de lembrete (lembrete = true, 7 dias), grava e devolve e-mail, nome e URL absoluta')]
    public function testReemite(): void
    {
        $user = self::pendente();
        $this->userRepository->method('find')->with(42)->willReturn($user);
        $this->confirmacaoRepository->method('existeLembreteDe')->with($user)->willReturn(false);
        $gravada = null;
        $this->confirmacaoRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(static function (ConfirmacaoEmail $c, bool $flush) use (&$gravada): void {
                self::assertTrue($flush);
                $gravada = $c;
            });

        $saida = $this->useCase()->executar(42);

        self::assertSame('ana@adv.com', $saida->email);
        self::assertSame('Ana Souza', $saida->nome);
        self::assertSame(1, preg_match('#^https://bluejus\.test/cadastro/confirmar-email/([a-f0-9]{64})$#', $saida->url, $achado));
        self::assertInstanceOf(ConfirmacaoEmail::class, $gravada);
        self::assertTrue($gravada->isLembrete());
        self::assertSame($user, $gravada->getUser());
        self::assertSame(ConfirmacaoEmail::hashDoToken($achado[1]), $gravada->getTokenHash());
        self::assertEquals(new \DateTimeImmutable('2026-10-26 10:00:00'), $gravada->getExpiraEm());
    }

    #[TestDox('Conta inexistente')]
    public function testContaInexistente(): void
    {
        $this->userRepository->method('find')->willReturn(null);
        $this->confirmacaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Conta não encontrada.');

        $this->useCase()->executar(42);
    }

    #[TestDox('E-mail já confirmado não ganha lembrete')]
    public function testJaConfirmado(): void
    {
        $this->userRepository->method('find')->willReturn((new User())->setEmail('ana@adv.com'));
        $this->confirmacaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('O e-mail desta conta já está confirmado.');

        $this->useCase()->executar(42);
    }

    #[TestDox('O lembrete é único por conta')]
    public function testLembreteJaEnviado(): void
    {
        $this->userRepository->method('find')->willReturn(self::pendente());
        $this->confirmacaoRepository->method('existeLembreteDe')->willReturn(true);
        $this->confirmacaoRepository->expects($this->never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('O lembrete desta conta já foi enviado.');

        $this->useCase()->executar(42);
    }
}
