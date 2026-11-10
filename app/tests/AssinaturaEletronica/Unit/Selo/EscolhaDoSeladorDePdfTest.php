<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Selo;

use App\AssinaturaEletronica\Service\Selo\EscolhaDoSeladorDePdf;
use App\AssinaturaEletronica\Service\Selo\HttpSeladorDePdf;
use App\AssinaturaEletronica\Service\Selo\SeladorDePdfNaoConfigurado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Qual selador vale (spec assinatura-documentos-etapa-8, seção 2): sem a URL ou sem o token do validador (vazios = não
 * configurado, etapa 6, seção 2), o nulo, e nenhum pedido sai; com os dois, o HTTP. A fiação no contêiner:
 * {@see \App\Tests\AssinaturaEletronica\Functional\FiacaoDoSeladorDePdfTest}.
 */
#[CoversClass(EscolhaDoSeladorDePdf::class)]
#[CoversClass(SeladorDePdfNaoConfigurado::class)]
final class EscolhaDoSeladorDePdfTest extends TestCase
{
    private const URL = 'http://validador_pdf:8080';
    private const TOKEN = 'token-inventado-do-teste-0123456789abcdef';

    private int $pedidos = 0;

    /** @return iterable<string, array{string, string}> */
    public static function semConfiguracao(): iterable
    {
        yield 'sem as duas' => ['', ''];
        yield 'sem o token' => [self::URL, ''];
        yield 'sem a URL' => ['', self::TOKEN];
        yield 'só espaços na URL' => ['   ', self::TOKEN];
        yield 'só espaços no token' => [self::URL, " \n"];
    }

    #[DataProvider('semConfiguracao')]
    #[TestDox('$_dataName: o selador nulo, que devolve nulo sem fazer pedido')]
    public function testSemConfiguracao(string $url, string $token): void
    {
        $selador = $this->escolha($url, $token)->selador();

        self::assertInstanceOf(SeladorDePdfNaoConfigurado::class, $selador);
        self::assertNull($selador->selar("%PDF-1.7\n%%EOF\n"));
        self::assertSame(0, $this->pedidos);
    }

    #[TestDox('com a URL e o token: o selador HTTP, que pede a /v1/selar')]
    public function testComConfiguracao(): void
    {
        $selador = $this->escolha(self::URL, self::TOKEN)->selador();

        self::assertInstanceOf(HttpSeladorDePdf::class, $selador);
        self::assertNull($selador->selar("%PDF-1.7\n%%EOF\n"), 'o validador respondeu 503 selo_nao_configurado');
        self::assertSame(1, $this->pedidos);
    }

    private function escolha(string $url, string $token): EscolhaDoSeladorDePdf
    {
        $http = new MockHttpClient(function (): MockResponse {
            ++$this->pedidos;

            return new MockResponse('{"v":1,"erro":"selo_nao_configurado"}', ['http_code' => 503]);
        });

        return new EscolhaDoSeladorDePdf($http, new LogDoSelo(), $url, $token);
    }
}
