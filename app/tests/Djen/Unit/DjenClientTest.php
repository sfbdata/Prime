<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\Enum\MotivoFalhaDjen;
use App\Djen\Exception\ConsultaDjenException;
use App\Djen\Service\DjenClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DjenClient::class)]
final class DjenClientTest extends TestCase
{
    private const BASE_URL = 'https://comunicaapi.pje.jus.br';

    #[Test]
    public function montaRequisicaoGetComOsParametrosDeConsulta(): void
    {
        $urlCapturada = null;
        $mock = new MockHttpClient(function (string $metodo, string $url) use (&$urlCapturada): MockResponse {
            $urlCapturada = $metodo . ' ' . $url;

            return new MockResponse((string) json_encode(['status' => 'success', 'count' => 0, 'items' => []]));
        });

        $client = new DjenClient($mock, self::BASE_URL);
        $client->consultarComunicacoes(new ConsultaComunicacoesQuery(
            numeroOab: '67228',
            ufOab: 'PR',
            dataDisponibilizacaoInicio: new \DateTimeImmutable('2026-07-06'),
            dataDisponibilizacaoFim: new \DateTimeImmutable('2026-07-06'),
            itensPorPagina: 100,
        ));

        self::assertNotNull($urlCapturada);
        self::assertStringStartsWith('GET ' . self::BASE_URL . '/api/v1/comunicacao', $urlCapturada);
        self::assertStringContainsString('numeroOab=67228', $urlCapturada);
        self::assertStringContainsString('ufOab=PR', $urlCapturada);
        self::assertStringContainsString('dataDisponibilizacaoInicio=2026-07-06', $urlCapturada);
        self::assertStringContainsString('itensPorPagina=100', $urlCapturada);
    }

    #[Test]
    public function envelopeDeSucessoRetornaCountEItens(): void
    {
        $payload = [
            'status' => 'success',
            'message' => 'Sucesso',
            'count' => 2,
            'items' => [
                ['id' => 659192456, 'numero_processo' => '50636766220224047000', 'siglaTribunal' => 'CJF'],
                ['id' => 659192457, 'numero_processo' => '50636766220224047001', 'siglaTribunal' => 'TJPR'],
            ],
        ];
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse((string) json_encode($payload)));

        $resposta = (new DjenClient($mock, self::BASE_URL))
            ->consultarComunicacoes(new ConsultaComunicacoesQuery(numeroOab: '67228', ufOab: 'PR'));

        self::assertSame(2, $resposta->count);
        self::assertSame(2, $resposta->quantidadeNaPagina());
        self::assertSame('50636766220224047000', $resposta->items[0]['numero_processo']);
    }

    #[Test]
    #[DataProvider('provedorStatus')]
    public function classificaStatusHttpComoMotivo(int $status, MotivoFalhaDjen $esperado): void
    {
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse('{"status":"error"}', ['http_code' => $status]));
        $client = new DjenClient($mock, self::BASE_URL);

        try {
            $client->consultarComunicacoes(new ConsultaComunicacoesQuery(numeroOab: '67228', ufOab: 'PR'));
            self::fail('deveria ter lançado ConsultaDjenException');
        } catch (ConsultaDjenException $e) {
            self::assertSame($esperado, $e->getMotivo());
        }
    }

    /**
     * @return iterable<string, array{int, MotivoFalhaDjen}>
     */
    public static function provedorStatus(): iterable
    {
        yield '429 limite' => [429, MotivoFalhaDjen::LimiteExcedido];
        yield '500 ocupado' => [500, MotivoFalhaDjen::SistemaOcupado];
        yield '503 indisponivel' => [503, MotivoFalhaDjen::Indisponivel];
        yield '401 indisponivel' => [401, MotivoFalhaDjen::Indisponivel];
        yield '422 resposta invalida' => [422, MotivoFalhaDjen::RespostaInvalida];
        yield '404 resposta invalida' => [404, MotivoFalhaDjen::RespostaInvalida];
    }

    #[Test]
    public function envelopeDeErroEmHttp200ViraSistemaOcupado(): void
    {
        $body = '{"status":"error","message":"O sistema está muito ocupado. Tente novamente mais tarde."}';
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));
        $client = new DjenClient($mock, self::BASE_URL);

        try {
            $client->consultarComunicacoes(new ConsultaComunicacoesQuery(numeroOab: '67228', ufOab: 'PR'));
            self::fail('deveria ter lançado ConsultaDjenException');
        } catch (ConsultaDjenException $e) {
            self::assertSame(MotivoFalhaDjen::SistemaOcupado, $e->getMotivo());
        }
    }

    #[Test]
    public function timeoutViraMotivoTimeout(): void
    {
        $mock = new MockHttpClient(function (): MockResponse {
            throw new TimeoutException('idle timeout');
        });

        try {
            (new DjenClient($mock, self::BASE_URL))
                ->consultarComunicacoes(new ConsultaComunicacoesQuery(numeroOab: '67228', ufOab: 'PR'));
            self::fail('deveria ter lançado ConsultaDjenException');
        } catch (ConsultaDjenException $e) {
            self::assertSame(MotivoFalhaDjen::Timeout, $e->getMotivo());
        }
    }

    #[Test]
    public function falhaDeTransporteViraIndisponivel(): void
    {
        $mock = new MockHttpClient(function (): MockResponse {
            throw new TransportException('connection refused');
        });

        try {
            (new DjenClient($mock, self::BASE_URL))
                ->consultarComunicacoes(new ConsultaComunicacoesQuery(numeroOab: '67228', ufOab: 'PR'));
            self::fail('deveria ter lançado ConsultaDjenException');
        } catch (ConsultaDjenException $e) {
            self::assertSame(MotivoFalhaDjen::Indisponivel, $e->getMotivo());
        }
    }

    #[Test]
    public function baseUrlVaziaFalhaAntesDeChamarApi(): void
    {
        $mock = new MockHttpClient(fn (): MockResponse => self::fail('a API não pode ser chamada sem base URL'));

        $this->expectException(ConsultaDjenException::class);

        (new DjenClient($mock, ''))
            ->consultarComunicacoes(new ConsultaComunicacoesQuery(numeroOab: '67228', ufOab: 'PR'));
    }
}
