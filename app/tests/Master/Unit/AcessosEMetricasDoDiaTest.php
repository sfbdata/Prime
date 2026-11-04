<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\EventListener\AcessoDiarioNoLoginListener;
use App\Master\Repository\AcessoDiarioRepository;
use App\Master\Repository\MetricaDiariaDoSistemaRepository;
use App\Master\Repository\PainelMasterRepository;
use App\Master\UseCase\RegistrarAcessoDoDiaUseCase;
use App\Master\UseCase\RetratarMetricasDoDiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Os dados da visão "Diário" e da linha "Acessos ao sistema" do gráfico Crescimento (desenho 05 - Perfis 1.2,
 * VS 03.11.26): o acesso do dia gravado no login, sem nunca derrubar o login, e a foto diária das métricas.
 */
#[CoversClass(RegistrarAcessoDoDiaUseCase::class)]
#[CoversClass(AcessoDiarioNoLoginListener::class)]
#[CoversClass(RetratarMetricasDoDiaUseCase::class)]
final class AcessosEMetricasDoDiaTest extends TestCase
{
    #[TestDox('o login grava o acesso do usuário no DIA (meia-noite), não na hora')]
    public function testGravaOAcessoNoDia(): void
    {
        $acessos = $this->createMock(AcessoDiarioRepository::class);
        $acessos->expects($this->once())->method('registrar')->with(42, new \DateTimeImmutable('2026-11-04 00:00:00'));

        $this->listener($acessos, $this->createStub(LoggerInterface::class))($this->login($this->usuario(42)));
    }

    #[TestDox('usuário ainda sem id não grava nada')]
    public function testUsuarioSemIdNaoGrava(): void
    {
        $acessos = $this->createMock(AcessoDiarioRepository::class);
        $acessos->expects($this->never())->method('registrar');

        $this->listener($acessos, $this->createStub(LoggerInterface::class))($this->login($this->usuario(null)));
    }

    #[TestDox('erro ao gravar o acesso vai para o log e o login segue')]
    public function testErroNaoDerrubaOLogin(): void
    {
        $acessos = $this->createStub(AcessoDiarioRepository::class);
        $acessos->method('registrar')->willThrowException(new \RuntimeException('banco fora'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->listener($acessos, $logger)($this->login($this->usuario(7)));
    }

    #[TestDox('a foto do dia grava o total de cada métrica, no dia de hoje')]
    public function testRetrataAsMetricasDoDia(): void
    {
        $painel = $this->createStub(PainelMasterRepository::class);
        $painel->method('entradasPorDia')->willReturn([
            'contas'   => ['2026-01-02' => 2.0, '2026-11-03' => 1.0],
            'usuarios' => ['2026-05-05' => 4.0],
            'pastas'   => [],
            'bytes'    => ['2026-02-02' => 1500.0, '2026-02-03' => 500.0],
        ]);
        $fotos = $this->createMock(MetricaDiariaDoSistemaRepository::class);
        $fotos->expects($this->once())->method('gravarDoDia')->with(
            new \DateTimeImmutable('2026-11-04 00:00:00'),
            ['contas' => 3, 'usuarios' => 4, 'pastas' => 0, 'bytes' => 2000],
            new \DateTimeImmutable('2026-11-04 23:50:00'),
        );

        $valores = (new RetratarMetricasDoDiaUseCase($painel, $fotos, new MockClock(new \DateTimeImmutable('2026-11-04 23:50:00'))))->executar();

        self::assertSame(['contas' => 3, 'usuarios' => 4, 'pastas' => 0, 'bytes' => 2000], $valores);
    }

    private function listener(AcessoDiarioRepository $acessos, LoggerInterface $logger): AcessoDiarioNoLoginListener
    {
        return new AcessoDiarioNoLoginListener(new RegistrarAcessoDoDiaUseCase($acessos, new MockClock(new \DateTimeImmutable('2026-11-04 15:30:00'))), $logger);
    }

    private function usuario(?int $id): User
    {
        $usuario = $this->createStub(User::class);
        $usuario->method('getId')->willReturn($id);
        $usuario->method('getRoles')->willReturn(['ROLE_USER']);
        $usuario->method('getUserIdentifier')->willReturn('pessoa@exemplo.com');

        return $usuario;
    }

    private function login(User $usuario): LoginSuccessEvent
    {
        return new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge('pessoa@exemplo.com', static fn (): User => $usuario)),
            new PostAuthenticationToken($usuario, 'main', ['ROLE_USER']),
            new Request(),
            null,
            'main',
        );
    }
}
