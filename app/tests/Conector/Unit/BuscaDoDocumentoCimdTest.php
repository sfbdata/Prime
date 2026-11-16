<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Exception\ClienteRecusado;
use App\Conector\Service\BuscaDoDocumentoCimd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A busca segura do documento CIMD (spec mcp-remoto-oauth 3.4 e 9.5; teste 12.12): rede privada, redirecionamento,
 * teto do corpo, status, limitador. Sem rede: {@see MockHttpClient} por baixo do `NoPrivateNetworkHttpClient`.
 */
#[CoversClass(BuscaDoDocumentoCimd::class)]
final class BuscaDoDocumentoCimdTest extends TestCase
{
    use MontaBuscaCimd;

    private const URL = 'https://claude.ai/oauth/claude-code-client-metadata';

    private function recusa(BuscaDoDocumentoCimd $busca, string $motivo, string $url = self::URL, string $host = 'claude.ai'): void
    {
        try {
            $busca->buscar($url, $host);
            self::fail('aceitou: ' . $motivo);
        } catch (ClienteRecusado $e) {
            self::assertSame($motivo, $e->motivo);
            self::assertSame($host, $e->host);
        }
    }

    #[TestDox('Busca com GET, Accept JSON, sem redirecionamento e com tempo curto; devolve o documento e a validade do cache')]
    public function testBuscaODocumento(): void
    {
        $resposta = self::resposta(self::documento(self::URL));
        $http = new MockHttpClient($resposta);

        $documento = self::busca($http)->buscar(self::URL, 'claude.ai');

        self::assertSame(self::URL, $documento->clientId);
        self::assertSame(['http://localhost/callback', 'http://127.0.0.1/callback'], $documento->redirectUris);
        self::assertEquals(new \DateTimeImmutable('2026-11-16 13:00:00'), $documento->validoAte);
        self::assertSame('GET', $resposta->getRequestMethod());
        self::assertSame(self::URL, $resposta->getRequestUrl());
        self::assertContains('Accept: application/json', $resposta->getRequestOptions()['headers']);
        self::assertSame(0, $resposta->getRequestOptions()['max_redirects']);
        self::assertSame(BuscaDoDocumentoCimd::TEMPO_EM_SEGUNDOS, $resposta->getRequestOptions()['timeout']);
        self::assertSame(BuscaDoDocumentoCimd::TEMPO_EM_SEGUNDOS, $resposta->getRequestOptions()['max_duration']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function enderecosPrivados(): iterable
    {
        yield 'rede 10' => ['https://10.0.0.5/cimd.json', '10.0.0.5'];
        yield 'rede 192.168' => ['https://192.168.1.1/cimd.json', '192.168.1.1'];
        yield 'loopback' => ['https://127.0.0.1/cimd.json', '127.0.0.1'];
        yield 'link-local (metadado de nuvem)' => ['https://169.254.169.254/latest/meta-data', '169.254.169.254'];
        yield 'loopback IPv6' => ['https://[::1]/cimd.json', '[::1]'];
        yield 'não especificado' => ['https://0.0.0.0/cimd.json', '0.0.0.0'];
    }

    #[DataProvider('enderecosPrivados')]
    #[TestDox('IP privado, loopback ou link-local é recusado sem chegar à rede: $_dataName')]
    public function testEnderecoPrivadoRecusado(string $url, string $host): void
    {
        $http = self::semRede();

        $this->recusa(self::busca($http, resolucao: []), ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL, $url, $host);
        self::assertSame(0, $http->getRequestsCount());
    }

    #[TestDox('Nome permitido que resolve para 10.x é recusado antes da conexão')]
    public function testNomeQueResolveParaRedePrivada(): void
    {
        $http = self::semRede();

        $this->recusa(self::busca($http, resolucao: ['claude.ai' => '10.1.2.3']), ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL);
        self::assertSame(0, $http->getRequestsCount());
    }

    #[TestDox('Conexão que cai num IP privado (troca de DNS depois da resolução) é recusada')]
    public function testConexaoEmIpPrivado(): void
    {
        $http = new MockHttpClient(new MockResponse(self::documento(self::URL), ['primary_ip' => '10.9.9.9']));

        $this->recusa(self::busca($http), ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL);
    }

    /** @return iterable<string, array{int}> */
    public static function redirecionamentos(): iterable
    {
        yield '301' => [301];
        yield '302' => [302];
        yield '307' => [307];
        yield '308' => [308];
    }

    #[DataProvider('redirecionamentos')]
    #[TestDox('Redirecionamento $_dataName recusa e não é seguido')]
    public function testRedirecionamentoRecusado(int $status): void
    {
        $http = new MockHttpClient([
            // `redirect_url` na info: é por ela que o cliente HTTP decide seguir (o MockHttpClient não lê o Location).
            new MockResponse('', ['http_code' => $status, 'redirect_url' => 'https://claude.ai/oauth/outro', 'response_headers' => ['Location: https://claude.ai/oauth/outro']]),
            self::resposta(self::documento('https://claude.ai/oauth/outro')),
        ]);

        $this->recusa(self::busca($http), ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL);
        self::assertSame(1, $http->getRequestsCount());
    }

    #[TestDox('Status diferente de 200 (404, 500, 204) recusa')]
    public function testStatusDiferenteDe200(): void
    {
        foreach ([404, 500, 204] as $status) {
            $this->recusa(self::busca(new MockHttpClient(self::resposta(self::documento(self::URL), $status))), ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL);
        }
    }

    #[TestDox('Corpo acima de 16 KiB em pedaços, sem Content-Length, recusa (JSON válido, só grande: é o teto que recusa)')]
    public function testCorpoAcimaDoTetoEmPedacos(): void
    {
        $pedacos = (static function (): \Generator {
            yield self::documento(self::URL);
            for ($i = 0; $i < 17; ++$i) {
                yield str_repeat(' ', 1024);
            }
        })();
        $http = new MockHttpClient(new MockResponse($pedacos, ['response_headers' => ['Content-Type: application/json']]));

        $this->recusa(self::busca($http), ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO);
    }

    #[TestDox('Documento válido acima do teto recusa, também com o Content-Length certo; exatamente no teto passa')]
    public function testTetoPeloTamanho(): void
    {
        $grande = self::documento(self::URL, extras: ['logo_uri' => str_repeat('a', BuscaDoDocumentoCimd::TETO_EM_BYTES)]);
        $this->recusa(self::busca(new MockHttpClient(self::resposta($grande))), ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO);
        $comTamanho = self::resposta($grande, 200, ['Content-Type: application/json', 'Content-Length: ' . \strlen($grande)]);
        $this->recusa(self::busca(new MockHttpClient($comTamanho)), ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO);

        $base = self::documento(self::URL, extras: ['logo_uri' => '']);
        $noTeto = self::documento(self::URL, extras: ['logo_uri' => str_repeat('a', BuscaDoDocumentoCimd::TETO_EM_BYTES - \strlen($base))]);
        self::assertSame(BuscaDoDocumentoCimd::TETO_EM_BYTES, \strlen($noTeto));
        self::assertSame(self::URL, self::busca(new MockHttpClient(self::resposta($noTeto)))->buscar(self::URL, 'claude.ai')->clientId);
    }

    #[TestDox('JSON inválido e client_id do documento diferente da URL buscada recusam como documento_invalido')]
    public function testDocumentoInvalido(): void
    {
        $this->recusa(self::busca(new MockHttpClient(self::resposta('<html>oi</html>'))), ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO);
        $this->recusa(self::busca(new MockHttpClient(self::resposta(self::documento('https://claude.ai/oauth/outro')))), ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO);
    }

    #[TestDox('Falha de rede (tempo esgotado) recusa como documento_indisponivel')]
    public function testFalhaDeRede(): void
    {
        $http = new MockHttpClient(new MockResponse([''], ['response_headers' => ['Content-Type: application/json']]));

        $this->recusa(self::busca($http), ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL);
    }

    #[TestDox('O limitador por domínio recusa a busca além do limite, sem chegar à rede; outro domínio segue')]
    public function testLimitadorPorDominio(): void
    {
        $http = new MockHttpClient(static fn (string $metodo, string $url): MockResponse => self::resposta(self::documento($url)));
        $busca = self::busca($http, limite: 2);

        $busca->buscar(self::URL, 'claude.ai');
        $busca->buscar(self::URL, 'claude.ai');
        $this->recusa($busca, ClienteRecusado::MOTIVO_LIMITE_DE_BUSCA);
        self::assertSame(2, $http->getRequestsCount());

        self::assertSame('https://chatgpt.com/oauth/client.json', $busca->buscar('https://chatgpt.com/oauth/client.json', 'chatgpt.com')->clientId);
    }
}
