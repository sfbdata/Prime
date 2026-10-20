<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Event\EventosDoEspacoAposConfirmar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(EventosDoEspacoAposConfirmar::class)]
final class EventosDoEspacoAposConfirmarTest extends TestCase
{
    /** @var list<object> */
    private array $despachados = [];

    #[TestDox('Resposta de sucesso despacha os eventos guardados, uma vez só')]
    public function testSucessoDespacha(): void
    {
        $eventos = $this->expedidor();
        $eventos->adiar($a = new \stdClass());

        $eventos->aoResponder($this->resposta(200));
        $eventos->aoResponder($this->resposta(200));

        self::assertSame([$a], $this->despachados);
    }

    #[TestDox('Resposta de erro (4xx/5xx) descarta: o upload pode ter sido desfeito')]
    public function testErroDescarta(): void
    {
        $eventos = $this->expedidor();
        $eventos->adiar(new \stdClass());

        $eventos->aoResponder($this->resposta(422));
        $eventos->aoResponder($this->resposta(200));

        self::assertSame([], $this->despachados);
    }

    #[TestDox('Exceção na requisição descarta')]
    public function testExcecaoDescarta(): void
    {
        $eventos = $this->expedidor();
        $eventos->adiar(new \stdClass());

        $eventos->aoFalhar(new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException()));
        $eventos->aoResponder($this->resposta(200));

        self::assertSame([], $this->despachados);
    }

    private function expedidor(): EventosDoEspacoAposConfirmar
    {
        $despachante = $this->createStub(EventDispatcherInterface::class);
        $despachante->method('dispatch')->willReturnCallback(function (object $e): object {
            $this->despachados[] = $e;

            return $e;
        });

        return new EventosDoEspacoAposConfirmar($despachante);
    }

    private function resposta(int $status): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new Response('', $status));
    }
}
