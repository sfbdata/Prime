<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Processo\Exception\TribunalNaoIdentificadoException;
use App\Processo\Service\DatajudClient;
use App\Processo\Service\TribunalCnjResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DatajudClient::class)]
final class DatajudClientTest extends TestCase
{
    private const BASE_URL = 'https://api-publica.datajud.cnj.jus.br';

    #[TestDox('O número deriva o índice do tribunal na URL e envia o número só com dígitos')]
    public function testDerivaUrlDoTribunalEEnviaNumeroNormalizado(): void
    {
        $capturado = ['url' => null, 'body' => null];
        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturado): MockResponse {
            $capturado['url'] = $url;
            $capturado['body'] = $options['body'] ?? null;

            return new MockResponse((string) json_encode(['hits' => ['hits' => []]]));
        });

        $client = new DatajudClient($mockClient, 'chave-fake', self::BASE_URL, new TribunalCnjResolver());

        // Número mascarado de TJSP (J=8, TR=26).
        $client->searchByNumeroProcesso('0001234-56.2020.8.26.0100');

        self::assertSame(
            self::BASE_URL . '/api_publica_tjsp/_search',
            $capturado['url'],
            'a URL deve usar o índice derivado do número (tjsp), sem sigla informada pelo usuário'
        );

        $payload = json_decode((string) $capturado['body'], true);
        self::assertSame(
            '00012345620208260100',
            $payload['query']['match']['numeroProcesso'] ?? null,
            'o número enviado à API deve estar normalizado (só dígitos)'
        );
    }

    #[TestDox('Índice eleitoral do DF vira tre-df (não o inexistente tre-dft)')]
    public function testEleitoralDfUsaIndiceCorreto(): void
    {
        $capturadaUrl = null;
        $mockClient = new MockHttpClient(function (string $method, string $url) use (&$capturadaUrl): MockResponse {
            $capturadaUrl = $url;

            return new MockResponse((string) json_encode(['hits' => ['hits' => []]]));
        });

        $client = new DatajudClient($mockClient, 'chave-fake', self::BASE_URL, new TribunalCnjResolver());

        // TRE-DF (J=6, TR=07).
        $client->searchByNumeroProcesso('0000000-00.2020.6.07.0000');

        self::assertSame(self::BASE_URL . '/api_publica_tre-df/_search', $capturadaUrl);
    }

    #[TestDox('Número não-CNJ lança TribunalNaoIdentificadoException antes de qualquer requisição HTTP')]
    public function testNumeroInvalidoLancaAntesDoHttp(): void
    {
        $mockClient = new MockHttpClient(function (): MockResponse {
            self::fail('a API não pode ser chamada quando o número não identifica o tribunal');
        });

        $client = new DatajudClient($mockClient, 'chave-fake', self::BASE_URL, new TribunalCnjResolver());

        $this->expectException(TribunalNaoIdentificadoException::class);
        $client->searchByNumeroProcesso('numero-invalido');
    }

    #[TestDox('Sem API key configurada, falha com RuntimeException (não confundir com tribunal)')]
    public function testSemApiKeyLancaRuntimeException(): void
    {
        $mockClient = new MockHttpClient(function (): MockResponse {
            self::fail('sem API key não deve chamar a API');
        });

        $client = new DatajudClient($mockClient, '', self::BASE_URL, new TribunalCnjResolver());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DATAJUD_API_KEY');
        $client->searchByNumeroProcesso('0001234-56.2020.8.26.0100');
    }
}
