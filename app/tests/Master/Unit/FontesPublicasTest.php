<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Fontes\ArmazemDeFontes;
use App\Master\Fontes\ClienteIbge;
use App\Master\Fontes\ClienteQuadroOab;
use App\Master\Fontes\FonteIndisponivel;
use App\Master\Fontes\FontesPublicas;
use App\Master\Fontes\LeitorQuadroOab;
use App\Master\Fontes\SementeQuadroOab;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(FontesPublicas::class)]
#[CoversClass(ClienteIbge::class)]
#[CoversClass(ArmazemDeFontes::class)]
final class FontesPublicasTest extends TestCase
{
    private const MALHA = '{"type":"FeatureCollection","features":[{"type":"Feature","properties":{"codarea":"2704302"},"geometry":null}]}';
    private const NOMES = '[{"id":2704302,"nome":"Maceió"}]';

    private ArrayAdapter $pool;
    private MockClock $relogio;
    /** @var list<string> */
    private array $urls = [];
    private bool $redeFora = false;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter(0, false);
        $this->relogio = new MockClock('2026-10-16 10:00:00');
    }

    #[TestDox('Municípios: busca no IBGE (malha + nomes), guarda e não busca de novo dentro de 30 dias')]
    public function testMunicipiosComCache(): void
    {
        $fontes = $this->fontes();

        $json = json_decode($fontes->municipios('al'), true);
        self::assertSame('2704302', $json['geo']['features'][0]['properties']['codarea']);
        self::assertSame(['2704302' => 'Maceió'], $json['nomes']);
        self::assertCount(2, $this->urls);
        self::assertStringContainsString('/api/v3/malhas/estados/27', $this->urls[0]);
        self::assertStringContainsString('intrarregiao=municipio', $this->urls[0]);

        $this->relogio->modify('+29 days');
        $fontes->municipios('AL');
        self::assertCount(2, $this->urls, 'dentro de 30 dias usa o retrato guardado');
    }

    #[TestDox('IBGE fora do ar: retrato vencido continua valendo; sem retrato nenhum, falha')]
    public function testFalhaMantemCache(): void
    {
        $fontes = $this->fontes();
        $fontes->municipios('AL');

        $this->relogio->modify('+40 days');
        $this->redeFora = true;
        $json = json_decode($fontes->municipios('AL'), true);
        self::assertSame('Maceió', $json['nomes']['2704302'], 'retrato antigo mantido quando o IBGE falha');

        $this->expectException(FonteIndisponivel::class);
        $fontes->municipios('SP');
    }

    #[TestDox('População: lê o agregado 6579 e a tabela enviada pelo Master se sobrepõe')]
    public function testPopulacao(): void
    {
        $fontes = $this->fontes();
        $fontes->gravarTabelaEnviada(['uf' => [], 'mun' => ['27' => ['2704302' => 1000]]], 'tabela.csv');

        $pop = $fontes->populacaoDosMunicipios('AL');

        self::assertSame('2025', $pop['ano']);
        self::assertSame(1000, $pop['valores']['2704302']);
        self::assertSame(50000, $pop['valores']['2700102']);
    }

    #[TestDox('OAB: sem retrato usa a semente declarada; atualizar grava o retrato; falha depois mantém o anterior')]
    public function testQuadroOab(): void
    {
        $fontes = $this->fontes();
        self::assertSame(SementeQuadroOab::DATA, $fontes->quadroOab()['data']);

        $resultado = $fontes->atualizar();
        self::assertSame(['ibge' => true, 'oab' => true], $resultado);
        self::assertSame('16/10/2026', $fontes->quadroOab()['data']);
        self::assertSame(396761, $fontes->quadroOab()['uf']['SP'][0]);

        $this->relogio->modify('+1 day');
        $this->redeFora = true;
        self::assertSame(['ibge' => false, 'oab' => false], $fontes->atualizar());
        self::assertSame('16/10/2026', $fontes->quadroOab()['data'], 'falha da OAB mantém o retrato anterior');

        $resumo = $fontes->resumo();
        self::assertSame(396761, $resumo['uf']['35']['adv']);
        self::assertSame(46000000, $resumo['uf']['35']['pop']);
    }

    private function fontes(): FontesPublicas
    {
        $http = new MockHttpClient(function (string $metodo, string $url): MockResponse {
            $this->urls[] = $url;
            if ($this->redeFora) {
                return new MockResponse('', ['http_code' => 503]);
            }
            if (str_contains($url, '/malhas/')) {
                return new MockResponse(self::MALHA);
            }
            if (str_contains($url, '/localidades/')) {
                return new MockResponse(self::NOMES);
            }
            if (str_contains($url, 'N6')) {
                return new MockResponse('[{"resultados":[{"series":[{"localidade":{"id":"2704302","nome":"Maceió - AL"},"serie":{"2025":"957916"}},{"localidade":{"id":"2700102","nome":"Água Branca - AL"},"serie":{"2025":"50000"}}]}]}]');
            }
            if (str_contains($url, '/agregados/')) {
                return new MockResponse('[{"resultados":[{"series":[{"localidade":{"id":"35","nome":"São Paulo"},"serie":{"2025":"46000000"}}]}]}]');
            }
            if (str_contains($url, 'oab.org.br')) {
                return new MockResponse((string) file_get_contents(__DIR__ . '/../Fixtures/oab-quadro-advogados-2026-10-16.html'));
            }

            return new MockResponse('', ['http_code' => 404]);
        });

        return new FontesPublicas(
            new ArmazemDeFontes($this->pool, $this->relogio),
            new ClienteIbge($http),
            new ClienteQuadroOab($http),
            new LeitorQuadroOab(),
            $this->relogio,
            new NullLogger(),
        );
    }
}
