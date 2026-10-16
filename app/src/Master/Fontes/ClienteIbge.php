<?php

declare(strict_types=1);

namespace App\Master\Fontes;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente das APIs públicas do IBGE usadas pelo mapa do Painel do Master. Só o SERVIDOR chama o
 * IBGE (spec perfis-master D8); o navegador lê o que o servidor guardou.
 *
 *  - malha dos municípios de uma UF: API de malhas v3, qualidade mínima;
 *  - nomes dos municípios: API de localidades v1;
 *  - população: agregado 6579 (estimativas de população), variável 9324, último período.
 *
 * Testável com MockHttpClient: nenhum teste toca a rede.
 */
final class ClienteIbge
{
    public const BASE = 'https://servicodados.ibge.gov.br';

    /** Código IBGE de cada UF. */
    public const CODIGO_UF = [
        'RO' => 11, 'AC' => 12, 'AM' => 13, 'RR' => 14, 'PA' => 15, 'AP' => 16, 'TO' => 17,
        'MA' => 21, 'PI' => 22, 'CE' => 23, 'RN' => 24, 'PB' => 25, 'PE' => 26, 'AL' => 27,
        'SE' => 28, 'BA' => 29, 'MG' => 31, 'ES' => 32, 'RJ' => 33, 'SP' => 35, 'PR' => 41,
        'SC' => 42, 'RS' => 43, 'MS' => 50, 'MT' => 51, 'GO' => 52, 'DF' => 53,
    ];

    private const TIMEOUT = 8.0;
    private const DURACAO_MAXIMA = 25.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * GeoJSON (texto cru, já validado) com os municípios da UF.
     *
     * @throws FonteIndisponivel
     */
    public function malhaDosMunicipios(int $codigoUf): string
    {
        $corpo = $this->baixar(sprintf('/api/v3/malhas/estados/%d', $codigoUf), [
            'formato'       => 'application/vnd.geo+json',
            'intrarregiao'  => 'municipio',
            'qualidade'     => 'minima',
        ]);

        $geo = self::json($corpo);
        if (!isset($geo['features']) || !is_array($geo['features']) || $geo['features'] === []) {
            throw new FonteIndisponivel('IBGE devolveu malha sem municípios.');
        }

        return $corpo;
    }

    /**
     * @return array<string, string> código do município => nome
     *
     * @throws FonteIndisponivel
     */
    public function nomesDosMunicipios(int $codigoUf): array
    {
        $lista = self::json($this->baixar(sprintf('/api/v1/localidades/estados/%d/municipios', $codigoUf), []));

        $nomes = [];
        foreach ($lista as $m) {
            if (is_array($m) && isset($m['id'], $m['nome'])) {
                $nomes[(string) $m['id']] = (string) $m['nome'];
            }
        }

        return $nomes;
    }

    /**
     * População estimada (último período). `$localidades` no formato da API: "N3[all]" (UFs) ou
     * "N6[N3[35]]" (municípios de uma UF).
     *
     * @return array{ano: string, valores: array<string, int>, nomes: array<string, string>}
     *
     * @throws FonteIndisponivel
     */
    public function populacao(string $localidades): array
    {
        $dados = self::json($this->baixar('/api/v3/agregados/6579/periodos/-1/variaveis/9324', ['localidades' => $localidades]));

        $ano = '';
        $valores = [];
        $nomes = [];
        foreach (($dados[0]['resultados'] ?? []) as $resultado) {
            foreach (($resultado['series'] ?? []) as $serie) {
                $id = (string) ($serie['localidade']['id'] ?? '');
                $periodo = is_array($serie['serie'] ?? null) ? $serie['serie'] : [];
                $chaveAno = (string) array_key_first($periodo);
                $valor = (int) ($periodo[$chaveAno] ?? 0);
                if ($id === '' || $valor <= 0) {
                    continue;
                }
                $ano = $chaveAno !== '' ? $chaveAno : $ano;
                $valores[$id] = $valor;
                $nomes[$id] = (string) ($serie['localidade']['nome'] ?? '');
            }
        }

        if ($valores === []) {
            throw new FonteIndisponivel('IBGE devolveu população vazia para ' . $localidades . '.');
        }

        return ['ano' => $ano, 'valores' => $valores, 'nomes' => $nomes];
    }

    /**
     * @param array<string, string> $query
     *
     * @throws FonteIndisponivel
     */
    private function baixar(string $caminho, array $query): string
    {
        try {
            $resposta = $this->httpClient->request('GET', self::BASE . $caminho, [
                'query'        => $query,
                'headers'      => ['Accept' => 'application/json', 'User-Agent' => 'JusPrime/1.0 (+https://bluejus.com.br)'],
                'timeout'      => self::TIMEOUT,
                'max_duration' => self::DURACAO_MAXIMA,
            ]);

            $status = $resposta->getStatusCode();
            if ($status >= 400) {
                throw new FonteIndisponivel(sprintf('IBGE respondeu HTTP %d em %s.', $status, $caminho));
            }

            return $resposta->getContent();
        } catch (ExceptionInterface $e) {
            throw new FonteIndisponivel('Falha ao acessar o IBGE: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws FonteIndisponivel
     */
    private static function json(string $corpo): array
    {
        try {
            $dados = json_decode($corpo, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FonteIndisponivel('IBGE devolveu conteúdo que não é JSON.', 0, $e);
        }

        if (!is_array($dados)) {
            throw new FonteIndisponivel('IBGE devolveu JSON inesperado.');
        }

        return $dados;
    }
}
