<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\EventListener\CabecalhosDeSegurancaDoMaster;
use App\Shared\Http\NonceDaCsp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Qual rota do Master recebe qual cabeçalho (auditoria I-xss 5.04). */
#[CoversClass(CabecalhosDeSegurancaDoMaster::class)]
#[CoversClass(NonceDaCsp::class)]
final class CabecalhosDeSegurancaDoMasterTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rotasDoPainel(): iterable
    {
        yield 'painel' => ['master_painel'];
        yield 'editor novo' => ['master_blog_artigos_novo'];
        yield 'editor editar' => ['master_blog_artigos_editar'];
    }

    #[DataProvider('rotasDoPainel')]
    #[TestDox('Painel e editor do blog: CSP do painel com o nonce da requisição, sem unsafe-inline nem CDN em script-src')]
    public function testRotasDoPainel(string $rota): void
    {
        [$resposta, $requisicao] = $this->responder($rota);

        $nonce = (string) $requisicao->attributes->get(NonceDaCsp::ATRIBUTO);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/]{24}$/', $nonce);
        $csp = (string) $resposta->headers->get('Content-Security-Policy');
        self::assertSame(CabecalhosDeSegurancaDoMaster::politicaDoPainel($nonce), $csp);
        self::assertStringContainsString("script-src 'self' 'nonce-" . $nonce . "';", $csp);
        $scriptSrc = (string) preg_replace('/^.*script-src ([^;]*);.*$/', '$1', $csp);
        self::assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        self::assertStringNotContainsString('https:', $scriptSrc);
        self::assertStringNotContainsString('code.jquery.com', $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
        self::assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
    }

    #[TestDox('Painel: o nonce que o template já usou é o mesmo do cabeçalho')]
    public function testNonceDoTemplateEhODoCabecalho(): void
    {
        $requisicao = new Request(attributes: ['_route' => 'master_painel']);
        $pilha = new RequestStack([$requisicao]);
        $doTemplate = (new NonceDaCsp($pilha))->nonce();

        [$resposta] = $this->responder('master_painel', $requisicao, $pilha);

        self::assertStringContainsString("'nonce-" . $doTemplate . "'", (string) $resposta->headers->get('Content-Security-Policy'));
    }

    #[TestDox('Prévia do blog: CSP estrita, sem script nem base')]
    public function testPrevia(): void
    {
        [$resposta] = $this->responder('master_blog_artigos_previa');

        $csp = (string) $resposta->headers->get('Content-Security-Policy');
        self::assertSame(CabecalhosDeSegurancaDoMaster::CSP_PREVIA_DO_BLOG, $csp);
        self::assertStringContainsString("script-src 'none'", $csp);
        self::assertStringContainsString("base-uri 'none'", $csp);
    }

    #[TestDox('Mapas continuam com a CSP estrita dos mapas')]
    public function testMapa(): void
    {
        [$resposta] = $this->responder('master_mapa_brasil');

        self::assertSame(CabecalhosDeSegurancaDoMaster::CSP_MAPA, $resposta->headers->get('Content-Security-Policy'));
    }

    #[TestDox('JSON do Master (master_dados_*) e demais rotas do blog: só nosniff; fora do Master, nada')]
    public function testSoNosniff(): void
    {
        foreach (['master_dados_visao_geral', 'master_blog_artigos_listar', 'master_blog_imagem'] as $rota) {
            [$resposta] = $this->responder($rota);
            self::assertFalse($resposta->headers->has('Content-Security-Policy'), $rota);
            self::assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'), $rota);
        }

        [$resposta] = $this->responder('contribua_index');
        self::assertFalse($resposta->headers->has('Content-Security-Policy'));
        self::assertFalse($resposta->headers->has('X-Content-Type-Options'));
    }

    /** @return array{Response, Request} */
    private function responder(string $rota, ?Request $requisicao = null, ?RequestStack $pilha = null): array
    {
        $requisicao ??= new Request(attributes: ['_route' => $rota]);
        $pilha ??= new RequestStack([$requisicao]);
        $resposta = new Response();
        $kernel = $this->createStub(HttpKernelInterface::class);

        (new CabecalhosDeSegurancaDoMaster(new NonceDaCsp($pilha)))(
            new ResponseEvent($kernel, $requisicao, HttpKernelInterface::MAIN_REQUEST, $resposta),
        );

        return [$resposta, $requisicao];
    }
}
