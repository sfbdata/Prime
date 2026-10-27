<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\EventListener\EnviarAvisosPendentesDoLoginSubscriber;
use App\Auth\Service\AvisoDeAcessoNovoMailerInterface;
use App\Auth\Service\AvisosPendentesDoLogin;
use App\Auth\Service\NavegadorDoAcesso;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(EnviarAvisosPendentesDoLoginSubscriber::class)]
#[CoversClass(AvisosPendentesDoLogin::class)]
final class EnviarAvisosPendentesDoLoginSubscriberTest extends TestCase
{
    private function terminar(EnviarAvisosPendentesDoLoginSubscriber $s): void
    {
        $s->aoTerminar(new TerminateEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), new Response()));
    }

    #[TestDox('agendar não envia; o terminate envia uma vez e esvazia a fila')]
    public function testEnviaNoTerminate(): void
    {
        $mailer = $this->createMock(AvisoDeAcessoNovoMailerInterface::class);
        $mailer->expects(self::once())->method('avisar');
        $avisos = new AvisosPendentesDoLogin($mailer, $this->createStub(LoggerInterface::class));
        $avisos->agendar(new User(), new \DateTimeImmutable(), NavegadorDoAcesso::deUserAgent('x'));
        $s = new EnviarAvisosPendentesDoLoginSubscriber($avisos);

        $this->terminar($s);
        $this->terminar($s);
    }

    #[TestDox('falha de envio no terminate não lança e vai para o log; a fila segue')]
    public function testFalhaSoLoga(): void
    {
        $mailer = $this->createMock(AvisoDeAcessoNovoMailerInterface::class);
        $mailer->expects(self::exactly(2))->method('avisar')->willThrowException(new \RuntimeException('SMTP'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('error');
        $avisos = new AvisosPendentesDoLogin($mailer, $logger);
        $avisos->agendar(new User(), new \DateTimeImmutable(), NavegadorDoAcesso::deUserAgent('x'));
        $avisos->agendar(new User(), new \DateTimeImmutable(), NavegadorDoAcesso::deUserAgent('y'));

        $this->terminar(new EnviarAvisosPendentesDoLoginSubscriber($avisos));
    }
}
