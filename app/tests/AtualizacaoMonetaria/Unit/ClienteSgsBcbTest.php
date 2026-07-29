<?php

declare(strict_types=1);

namespace App\Tests\AtualizacaoMonetaria\Unit;

use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Exception\ImportacaoIndicesException;
use App\AtualizacaoMonetaria\Service\ClienteSgsBcb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ClienteSgsBcb::class)]
final class ClienteSgsBcbTest extends TestCase
{
    private const BASE_URL = 'https://api.bcb.gov.br';

    /**
     * Resposta real da série 188 (INPC), gravada em 29/07/2026. Sem rede no teste.
     *
     * @return list<array{data: string, valor: string}>
     */
    private static function respostaInpc(): array
    {
        return [
            ['data' => '01/12/1993', 'valor' => '36.83'],
            ['data' => '01/01/1994', 'valor' => '41.32'],
            ['data' => '01/02/1994', 'valor' => '40.57'],
            ['data' => '01/06/2026', 'valor' => '0.14'],
        ];
    }

    #[Test]
    public function traduzOJsonDoBcbParaCompetenciaMaisVariacao(): void
    {
        $mock = new MockHttpClient(
            fn (): MockResponse => new MockResponse((string) json_encode(self::respostaInpc()))
        );

        $serie = (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC);

        self::assertSame(
            [
                '1994-01-01' => '41.32',
                '1994-02-01' => '40.57',
                '2026-06-01' => '0.14',
            ],
            $serie,
        );
    }

    #[Test]
    public function descartaCompetenciasAnterioresA1994(): void
    {
        $mock = new MockHttpClient(
            fn (): MockResponse => new MockResponse((string) json_encode(self::respostaInpc()))
        );

        $serie = (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC);

        // 12/1993 existe na resposta do BCB e não pode entrar: antes do Plano Real a série
        // está em outra moeda e o percentual não é comparável.
        self::assertArrayNotHasKey('1993-12-01', $serie);
    }

    /**
     * A armadilha medida em 29/07/2026: a janela ?dataInicial/?dataFinal devolve lista VAZIA
     * mesmo quando o dado existe. O cliente tem de baixar a série INTEIRA e filtrar em PHP.
     */
    #[Test]
    public function baixaASerieInteiraSemFiltroDeDataNaUrl(): void
    {
        $urlCapturada = null;
        $mock = new MockHttpClient(function (string $metodo, string $url) use (&$urlCapturada): MockResponse {
            $urlCapturada = $metodo . ' ' . $url;

            return new MockResponse((string) json_encode(self::respostaInpc()));
        });

        (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC);

        self::assertSame(
            'GET ' . self::BASE_URL . '/dados/serie/bcdata.sgs.188/dados?formato=json',
            $urlCapturada,
        );
        self::assertStringNotContainsString('dataInicial', (string) $urlCapturada);
        self::assertStringNotContainsString('dataFinal', (string) $urlCapturada);
    }

    #[Test]
    #[DataProvider('provedorSeries')]
    public function usaOCodigoSgsDeCadaSerie(SerieIndice $serie, string $codigoEsperado): void
    {
        $urlCapturada = null;
        $mock = new MockHttpClient(function (string $metodo, string $url) use (&$urlCapturada): MockResponse {
            $urlCapturada = $url;

            return new MockResponse('[]');
        });

        (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie($serie);

        self::assertStringContainsString('bcdata.sgs.' . $codigoEsperado . '/dados', (string) $urlCapturada);
    }

    /**
     * @return iterable<string, array{SerieIndice, string}>
     */
    public static function provedorSeries(): iterable
    {
        yield 'INPC' => [SerieIndice::INPC, '188'];
        yield 'IPCA' => [SerieIndice::IPCA, '433'];
        yield 'taxa legal' => [SerieIndice::TAXA_LEGAL, '29543'];
    }

    #[Test]
    public function preservaAsSeisCasasDecimaisDaTaxaLegal(): void
    {
        $payload = [
            ['data' => '01/08/2024', 'valor' => '0.098765'],
            ['data' => '01/09/2024', 'valor' => '0.450641'],
        ];
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse((string) json_encode($payload)));

        $serie = (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::TAXA_LEGAL);

        // String, não float: o valor vai para BCMath e para numeric(12,6) sem passar por binário.
        self::assertSame(['2024-08-01' => '0.098765', '2024-09-01' => '0.450641'], $serie);
    }

    #[Test]
    public function respostaVaziaDevolveListaVaziaSemErro(): void
    {
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse('[]'));

        self::assertSame([], (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC));
    }

    #[Test]
    public function ordenaPorCompetenciaAindaQueOBcbDevolvaForaDeOrdem(): void
    {
        $payload = [
            ['data' => '01/03/2020', 'valor' => '0.18'],
            ['data' => '01/01/2020', 'valor' => '0.19'],
            ['data' => '01/02/2020', 'valor' => '0.17'],
        ];
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse((string) json_encode($payload)));

        $serie = (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC);

        self::assertSame(['2020-01-01', '2020-02-01', '2020-03-01'], array_keys($serie));
    }

    #[Test]
    public function statusHttpDeErroLancaExcecao(): void
    {
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse('erro', ['http_code' => 500]));

        $this->expectException(ImportacaoIndicesException::class);
        $this->expectExceptionMessageMatches('/500/');

        (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC);
    }

    #[Test]
    public function timeoutLancaExcecao(): void
    {
        $mock = new MockHttpClient(fn (): MockResponse => throw new TimeoutException('estourou'));

        $this->expectException(ImportacaoIndicesException::class);

        (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC);
    }

    #[Test]
    public function falhaDeTransporteLancaExcecao(): void
    {
        $mock = new MockHttpClient(fn (): MockResponse => throw new TransportException('sem rede'));

        $this->expectException(ImportacaoIndicesException::class);

        (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC);
    }

    #[Test]
    public function corpoQueNaoEJsonLancaExcecao(): void
    {
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse('<html>manutenção</html>'));

        $this->expectException(ImportacaoIndicesException::class);

        (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC);
    }

    /**
     * O BCB já devolveu HTML de manutenção com 200. Um registro sem `data`/`valor`, ou com valor
     * não numérico, não pode virar índice silenciosamente — seria dinheiro errado no cálculo.
     */
    #[Test]
    #[DataProvider('provedorRegistroInvalido')]
    public function registroMalformadoLancaExcecao(mixed $registro): void
    {
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse((string) json_encode([$registro])));

        $this->expectException(ImportacaoIndicesException::class);

        (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provedorRegistroInvalido(): iterable
    {
        yield 'sem data' => [['valor' => '1.00']];
        yield 'sem valor' => [['data' => '01/01/2020']];
        yield 'data em formato desconhecido' => [['data' => '2020-01-01', 'valor' => '1.00']];
        yield 'data inexistente no calendário' => [['data' => '01/13/2020', 'valor' => '1.00']];
        yield 'valor não numérico' => [['data' => '01/01/2020', 'valor' => 'indisponível']];
        yield 'registro não é objeto' => ['linha solta'];
    }

    /**
     * Duas leituras para a mesma competência significam que a série mudou de forma (deixou de ser
     * mensal, por exemplo). Colapsar em silêncio perderia dado; melhor recusar a importação.
     */
    #[Test]
    public function competenciaRepetidaLancaExcecao(): void
    {
        $payload = [
            ['data' => '01/01/2020', 'valor' => '0.19'],
            ['data' => '01/01/2020', 'valor' => '0.21'],
        ];
        $mock = new MockHttpClient(fn (): MockResponse => new MockResponse((string) json_encode($payload)));

        $this->expectException(ImportacaoIndicesException::class);

        (new ClienteSgsBcb($mock, self::BASE_URL))->baixarSerie(SerieIndice::INPC);
    }

    #[Test]
    public function baseUrlVaziaLancaExcecaoAntesDeChamarARede(): void
    {
        $chamou = false;
        $mock = new MockHttpClient(function () use (&$chamou): MockResponse {
            $chamou = true;

            return new MockResponse('[]');
        });

        try {
            (new ClienteSgsBcb($mock, '  '))->baixarSerie(SerieIndice::INPC);
            self::fail('deveria ter lançado ImportacaoIndicesException');
        } catch (ImportacaoIndicesException) {
            self::assertFalse($chamou, 'não pode chamar a rede sem base url configurada');
        }
    }
}
