<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Enum\TipoDeEventoDeDesempenho;
use App\Master\EventListener\EventoDeLoginNoMonitorListener;
use App\Master\Repository\EventoDeDesempenhoRepository;
use App\Master\UseCase\RegistrarEventoDeDesempenhoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Login no monitor (spec master-desempenho D2 e §2.1): a falha entra sem usuário; o erro de gravação não derruba o login.
 */
#[CoversClass(EventoDeLoginNoMonitorListener::class)]
final class EventoDeLoginNoMonitorListenerTest extends TestCase
{
    #[TestDox('falha de login grava evento "login" com ok=false e SEM usuário')]
    public function testFalhaSemUsuario(): void
    {
        $repo = $this->createMock(EventoDeDesempenhoRepository::class);
        $gravado = null;
        $repo->expects($this->once())->method('salvar')->willReturnCallback(function ($evento) use (&$gravado): void {
            $gravado = $evento;
        });
        $listener = new EventoDeLoginNoMonitorListener(
            new RegistrarEventoDeDesempenhoUseCase($repo, new MockClock('2026-10-26 12:00:00')),
            $this->createStub(LoggerInterface::class),
        );

        $listener->aoFalhar($this->falha());

        self::assertSame(TipoDeEventoDeDesempenho::Login, $gravado->getTipo());
        self::assertFalse($gravado->getOk());
        self::assertNull($gravado->getUsuario());
        self::assertNull($gravado->getMensagem(), 'nem e-mail digitado nem motivo');
    }

    #[TestDox('erro ao gravar é registrado no log e engolido')]
    public function testErroNaoDerrubaOLogin(): void
    {
        $repo = $this->createMock(EventoDeDesempenhoRepository::class);
        $repo->method('salvar')->willThrowException(new \RuntimeException('banco fora'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $listener = new EventoDeLoginNoMonitorListener(new RegistrarEventoDeDesempenhoUseCase($repo, new MockClock()), $logger);

        $listener->aoFalhar($this->falha());

        $this->addToAssertionCount(1); // não lançou
    }

    private function falha(): LoginFailureEvent
    {
        return new LoginFailureEvent(
            new BadCredentialsException(),
            $this->createStub(AuthenticatorInterface::class),
            new Request(),
            new Response(),
            'main',
        );
    }
}
