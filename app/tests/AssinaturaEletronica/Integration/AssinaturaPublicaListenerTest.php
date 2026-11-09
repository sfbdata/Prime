<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Integration;

use App\AssinaturaEletronica\EventListener\AssinaturaPublicaListener;
use App\AssinaturaEletronica\Log\MascaraDoTokenNoLog;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * O listener da tela pública no container de verdade (contrato da etapa 2, seção 5.2): o filtro de escritório só sai
 * nas rotas públicas, com fronteira no nome; os cabeçalhos saem pelo caminho. E o processador que tira o token do log
 * está pendurado no logger do canal `request` (o do "Matched route").
 */
#[CoversClass(AssinaturaPublicaListener::class)]
#[CoversClass(MascaraDoTokenNoLog::class)]
final class AssinaturaPublicaListenerTest extends KernelTestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function rotas(): iterable
    {
        yield 'a página' => ['assinatura_eletronica_publica', true];
        yield 'o pedido de código' => ['assinatura_eletronica_publica_codigo', true];
        yield 'a reserva' => ['assinatura_eletronica_publica_invalido', true];
        yield 'nome parecido sem o sublinhado' => ['assinatura_eletronica_publicacao_x', false];
        yield 'nome parecido colado' => ['assinatura_eletronica_publicar', false];
        yield 'rota interna' => ['assinatura_eletronica_enviar', false];
        yield 'sem rota' => [null, false];
    }

    #[DataProvider('rotas')]
    #[TestDox('filtro de escritório na rota $_dataName')]
    public function testFiltroDeEscritorio(?string $rota, bool $desliga): void
    {
        self::bootKernel();
        $filtros = static::getContainer()->get(EntityManagerInterface::class)->getFilters();
        $filtros->enable('tenant');
        $request = Request::create('/qualquer');
        $request->attributes->set('_route', $rota);

        static::getContainer()->get(AssinaturaPublicaListener::class)->aoReceber(new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame(!$desliga, $filtros->isEnabled('tenant'));
        if ($filtros->isEnabled('tenant')) {
            $filtros->disable('tenant');
        }
    }

    /** @return iterable<string, array{string, ?string, bool}> */
    public static function respostas(): iterable
    {
        yield '/assinar sem barra, sem rota (404)' => ['/assinar', null, true];
        yield 'token com método errado, sem rota (405)' => ['/assinar/' . str_repeat('a', 64), null, true];
        yield 'rota pública' => ['/assinar/' . str_repeat('a', 64) . '/codigo', 'assinatura_eletronica_publica_codigo', true];
        yield 'caminho codificado' => ['/%61ssinar/x', null, true];
        yield 'tela interna' => ['/assinatura/documentos', 'assinatura_eletronica_listar', false];
    }

    #[DataProvider('respostas')]
    #[TestDox('cabeçalhos pelo caminho: $_dataName')]
    public function testCabecalhosPeloCaminho(string $caminho, ?string $rota, bool $protege): void
    {
        self::bootKernel();
        $request = Request::create($caminho);
        $request->attributes->set('_route', $rota);
        $resposta = new Response('', Response::HTTP_NOT_FOUND);

        static::getContainer()->get(AssinaturaPublicaListener::class)->aoResponder(new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $resposta));

        self::assertSame($protege ? 'noindex, nofollow' : null, $resposta->headers->get('X-Robots-Tag'));
        self::assertSame($protege ? 'no-referrer' : null, $resposta->headers->get('Referrer-Policy'));
    }

    #[TestDox('o processador que tira o token do log está no logger do canal request')]
    public function testProcessadorNoLoggerDoRequest(): void
    {
        self::bootKernel();
        $logger = static::getContainer()->get('monolog.logger.request');
        self::assertInstanceOf(Logger::class, $logger);

        $processadores = array_filter($logger->getProcessors(), static fn (mixed $p): bool => $p instanceof MascaraDoTokenNoLog);
        self::assertCount(1, $processadores);
    }
}
