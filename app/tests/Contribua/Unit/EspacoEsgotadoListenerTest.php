<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\OrigemDoLimite;
use App\Contribua\EventListener\EspacoEsgotadoListener;
use App\Contribua\Exception\EspacoEsgotado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(EspacoEsgotadoListener::class)]
#[CoversClass(ConfiguracaoDoEspaco::class)]
final class EspacoEsgotadoListenerTest extends TestCase
{
    #[TestDox('Formulário comum: aviso e volta para a página de origem')]
    public function testFormularioVoltaComAviso(): void
    {
        $request = Request::create('https://app.test/cobranca/acordo/3/documento', 'POST');
        $request->headers->set('referer', 'https://app.test/cobranca/acordo/3');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $resposta = $this->responder($request, new EspacoEsgotado(self::retrato()));

        self::assertInstanceOf(RedirectResponse::class, $resposta);
        self::assertSame('https://app.test/cobranca/acordo/3', $resposta->getTargetUrl());
        self::assertSame([EspacoEsgotado::MENSAGEM], $session->getFlashBag()->get('warning'));
    }

    #[TestDox('Referer de outro site não vira redirecionamento aberto')]
    public function testRefererDeForaVoltaParaAInicial(): void
    {
        $request = Request::create('https://app.test/x', 'POST');
        $request->headers->set('referer', 'https://app.test.malicioso.com/');

        $resposta = $this->responder($request, new EspacoEsgotado(self::retrato()));

        self::assertInstanceOf(RedirectResponse::class, $resposta);
        self::assertSame('https://app.test/inicio', $resposta->getTargetUrl());
    }

    #[TestDox('fetch com X-CSRF-Token (sem X-Requested-With) recebe JSON 422')]
    public function testFetchRecebeJson(): void
    {
        $request = Request::create('https://app.test/pasta/1/peticionar/upload', 'POST');
        $request->headers->set('X-CSRF-Token', 'abc');

        $resposta = $this->responder($request, new \RuntimeException('embrulho', 0, new EspacoEsgotado(self::retrato())));

        self::assertInstanceOf(JsonResponse::class, $resposta);
        self::assertSame(422, $resposta->getStatusCode());
        $dados = json_decode((string) $resposta->getContent(), true);
        self::assertSame('espaco_esgotado', $dados['codigo']);
        self::assertFalse($dados['success']);
    }

    #[TestDox('Outra exceção passa intocada')]
    public function testOutraExcecaoNaoMexe(): void
    {
        self::assertNull($this->responder(Request::create('/'), new \RuntimeException('outra coisa')));
    }

    #[TestDox('Faixas fora de ordem são recusadas')]
    public function testFaixasForaDeOrdem(): void
    {
        $this->expectException(\DomainException::class);

        (new ConfiguracaoDoEspaco())->definirFaixas(70, 95, 90, 100);
    }

    private function responder(Request $request, \Throwable $erro): ?\Symfony\Component\HttpFoundation\Response
    {
        $evento = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $erro);
        (new EspacoEsgotadoListener())->aoFalhar($evento);

        return $evento->getResponse();
    }

    private static function retrato(): RetratoDoEspaco
    {
        return new RetratoDoEspaco(7, 11, 10, 110, FaixaDoEspaco::Bloqueio, OrigemDoLimite::Plano, 'free', 'Free', null, OrigemDoLimite::Plano, true);
    }
}
