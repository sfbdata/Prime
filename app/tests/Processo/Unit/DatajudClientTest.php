<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Processo\Enum\MotivoFalhaDatajud;
use App\Processo\Exception\ConsultaDatajudException;
use App\Processo\Exception\TribunalNaoIdentificadoException;
use App\Processo\Service\DatajudClient;
use App\Processo\Service\TribunalCnjResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
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

    #[TestDox('Sem API key configurada, falha como NaoConfigurado sem chamar a API')]
    public function testSemApiKeyFalhaComoNaoConfigurado(): void
    {
        $mockClient = new MockHttpClient(function (): MockResponse {
            self::fail('sem API key não deve chamar a API');
        });

        $client = new DatajudClient($mockClient, '', self::BASE_URL, new TribunalCnjResolver());

        try {
            $client->searchByNumeroProcesso('0001234-56.2020.8.26.0100');
            self::fail('deveria ter lançado ConsultaDatajudException');
        } catch (ConsultaDatajudException $e) {
            self::assertSame(MotivoFalhaDatajud::NaoConfigurado, $e->getMotivo());
        }
    }

    #[DataProvider('statusProvider')]
    #[TestDox('HTTP $status do CNJ vira o motivo esperado')]
    public function testClassificaStatusHttp(int $status, MotivoFalhaDatajud $motivoEsperado): void
    {
        $mockClient = new MockHttpClient(
            fn (): MockResponse => new MockResponse('{"error":"x"}', ['http_code' => $status])
        );
        $client = new DatajudClient($mockClient, 'chave-fake', self::BASE_URL, new TribunalCnjResolver());

        try {
            $client->searchByNumeroProcesso('0001234-56.2020.8.26.0100');
            self::fail('deveria ter lançado ConsultaDatajudException');
        } catch (ConsultaDatajudException $e) {
            self::assertSame($motivoEsperado, $e->getMotivo());
        }
    }

    /**
     * @return iterable<string, array{int, MotivoFalhaDatajud}>
     */
    public static function statusProvider(): iterable
    {
        yield '429 -> limite excedido'          => [429, MotivoFalhaDatajud::LimiteExcedido];
        yield '401 -> nao configurado'          => [401, MotivoFalhaDatajud::NaoConfigurado];
        yield '403 -> nao configurado'          => [403, MotivoFalhaDatajud::NaoConfigurado];
        yield '404 -> tribunal sem base publica' => [404, MotivoFalhaDatajud::TribunalSemBasePublica];
        yield '500 -> indisponivel'             => [500, MotivoFalhaDatajud::Indisponivel];
        yield '503 -> indisponivel'             => [503, MotivoFalhaDatajud::Indisponivel];
        yield '400 -> resposta invalida'        => [400, MotivoFalhaDatajud::RespostaInvalida];
    }

    #[TestDox('Timeout da requisição vira o motivo Timeout')]
    public function testTimeoutViraMotivoTimeout(): void
    {
        $mockClient = new MockHttpClient(function (): MockResponse {
            throw new TimeoutException('idle timeout');
        });
        $client = new DatajudClient($mockClient, 'chave-fake', self::BASE_URL, new TribunalCnjResolver());

        try {
            $client->searchByNumeroProcesso('0001234-56.2020.8.26.0100');
            self::fail('deveria ter lançado ConsultaDatajudException');
        } catch (ConsultaDatajudException $e) {
            self::assertSame(MotivoFalhaDatajud::Timeout, $e->getMotivo());
        }
    }

    #[TestDox('Erro de rede (transporte) vira Indisponivel')]
    public function testErroDeRedeViraIndisponivel(): void
    {
        $mockClient = new MockHttpClient(function (): MockResponse {
            throw new TransportException('connection refused');
        });
        $client = new DatajudClient($mockClient, 'chave-fake', self::BASE_URL, new TribunalCnjResolver());

        try {
            $client->searchByNumeroProcesso('0001234-56.2020.8.26.0100');
            self::fail('deveria ter lançado ConsultaDatajudException');
        } catch (ConsultaDatajudException $e) {
            self::assertSame(MotivoFalhaDatajud::Indisponivel, $e->getMotivo());
        }
    }

    #[TestDox('Resposta 200 que não é JSON vira RespostaInvalida')]
    public function testRespostaNaoJsonViraRespostaInvalida(): void
    {
        $mockClient = new MockHttpClient(
            fn (): MockResponse => new MockResponse('<html>indisponivel</html>', ['http_code' => 200])
        );
        $client = new DatajudClient($mockClient, 'chave-fake', self::BASE_URL, new TribunalCnjResolver());

        try {
            $client->searchByNumeroProcesso('0001234-56.2020.8.26.0100');
            self::fail('deveria ter lançado ConsultaDatajudException');
        } catch (ConsultaDatajudException $e) {
            self::assertSame(MotivoFalhaDatajud::RespostaInvalida, $e->getMotivo());
        }
    }
}
