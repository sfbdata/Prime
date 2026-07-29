<?php

declare(strict_types=1);

namespace App\AtualizacaoMonetaria\Service;

use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Exception\ImportacaoIndicesException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente da API pública do SGS (Sistema Gerenciador de Séries Temporais) do Banco Central.
 *
 * Consulta 100% pública, sem chave. Depende de HttpClientInterface para ser testável com
 * MockHttpClient — nenhum teste desta classe toca a rede.
 *
 * ⚠️ Armadilha medida em 29/07/2026: os filtros `?dataInicial=&dataFinal=` da API devolvem lista
 * VAZIA mesmo quando o dado existe (a janela 01/01/1994–31/03/1994 na série 188 volta `[]`, embora
 * 01/1994 = 41,32). Confiar na janela produziria importação silenciosamente vazia. Por isso aqui se
 * baixa a série INTEIRA e o recorte é feito em PHP.
 */
final class ClienteSgsBcb implements ClienteSgsBcbInterface
{
    private const CAMINHO_SERIE = '/dados/serie/bcdata.sgs.%s/dados';
    private const TIMEOUT_SEGUNDOS = 30.0;
    private const MAX_DURATION_SEGUNDOS = 120.0;

    /**
     * Competência mais antiga que entra na tabela. Antes do Plano Real a série está em outra moeda
     * e o percentual mensal não é comparável — a spec §8 manda a carga histórica começar em 01/1994.
     */
    public const COMPETENCIA_MINIMA = '1994-01-01';

    private string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $bcbSgsBaseUrl,
    ) {
        $this->baseUrl = rtrim($bcbSgsBaseUrl, '/');
    }

    /**
     * Baixa a série completa e devolve as competências a partir de 01/1994, ordenadas.
     *
     * @return array<string, string> competência 'Y-m-01' => variação percentual (string, para BCMath)
     *
     * @throws ImportacaoIndicesException em qualquer falha de rede ou de conteúdo
     */
    public function baixarSerie(SerieIndice $serie): array
    {
        if (trim($this->baseUrl) === '') {
            throw new ImportacaoIndicesException('BCB_SGS_BASE_URL não configurada.');
        }

        $url = $this->baseUrl . sprintf(self::CAMINHO_SERIE, $serie->codigoSgs());

        try {
            $response = $this->httpClient->request('GET', $url, [
                // Sem dataInicial/dataFinal DE PROPÓSITO — ver o aviso no docblock da classe.
                'query' => ['formato' => 'json'],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'JusPrime/1.0 (+https://bluejus.com.br)',
                ],
                'timeout' => self::TIMEOUT_SEGUNDOS,
                'max_duration' => self::MAX_DURATION_SEGUNDOS,
            ]);

            // getStatusCode() aguarda os headers sem lançar em 4xx/5xx — permite a mensagem com o status.
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new ImportacaoIndicesException(sprintf(
                    'BCB respondeu HTTP %d para a série %s.',
                    $status,
                    $serie->codigoSgs(),
                ));
            }

            $dados = $response->toArray(false);
        } catch (TimeoutExceptionInterface $e) {
            throw new ImportacaoIndicesException('Timeout ao consultar o BCB: ' . $e->getMessage(), 0, $e);
        } catch (DecodingExceptionInterface | \JsonException $e) {
            throw new ImportacaoIndicesException('Resposta do BCB não é JSON válido: ' . $e->getMessage(), 0, $e);
        } catch (TransportExceptionInterface $e) {
            throw new ImportacaoIndicesException('BCB indisponível: ' . $e->getMessage(), 0, $e);
        }

        return $this->normalizar($dados, $serie);
    }

    /**
     * @param array<mixed> $dados
     *
     * @return array<string, string>
     */
    private function normalizar(array $dados, SerieIndice $serie): array
    {
        $serieNormalizada = [];

        foreach ($dados as $registro) {
            if (!is_array($registro)) {
                throw new ImportacaoIndicesException(sprintf(
                    'Registro fora do formato esperado na série %s.',
                    $serie->codigoSgs(),
                ));
            }

            $competencia = $this->competencia($registro['data'] ?? null, $serie);
            $variacao = $this->variacao($registro['valor'] ?? null, $serie, $competencia);

            if ($competencia < self::COMPETENCIA_MINIMA) {
                continue;
            }

            if (isset($serieNormalizada[$competencia])) {
                throw new ImportacaoIndicesException(sprintf(
                    'Competência %s repetida na série %s — a série deixou de ser mensal?',
                    $competencia,
                    $serie->codigoSgs(),
                ));
            }

            $serieNormalizada[$competencia] = $variacao;
        }

        ksort($serieNormalizada);

        return $serieNormalizada;
    }

    /** Traduz o `dd/mm/aaaa` do BCB para o dia 1 da competência ('Y-m-01'). */
    private function competencia(mixed $data, SerieIndice $serie): string
    {
        if (!is_string($data) || preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $data, $partes) !== 1) {
            throw new ImportacaoIndicesException(sprintf(
                'Data "%s" fora do formato dd/mm/aaaa na série %s.',
                is_scalar($data) ? (string) $data : gettype($data),
                $serie->codigoSgs(),
            ));
        }

        [, $dia, $mes, $ano] = $partes;

        if (checkdate((int) $mes, (int) $dia, (int) $ano) === false) {
            throw new ImportacaoIndicesException(sprintf(
                'Data "%s" não existe no calendário (série %s).',
                $data,
                $serie->codigoSgs(),
            ));
        }

        return sprintf('%s-%s-01', $ano, $mes);
    }

    /** Mantém o valor como STRING: ele vai para BCMath e para numeric(12,6) sem passar por float. */
    private function variacao(mixed $valor, SerieIndice $serie, string $competencia): string
    {
        if (!is_string($valor) && !is_int($valor) && !is_float($valor)) {
            throw new ImportacaoIndicesException(sprintf(
                'Variação ausente para a competência %s na série %s.',
                $competencia,
                $serie->codigoSgs(),
            ));
        }

        $texto = trim((string) $valor);

        if (is_numeric($texto) === false) {
            throw new ImportacaoIndicesException(sprintf(
                'Variação "%s" não é numérica (competência %s, série %s).',
                $texto,
                $competencia,
                $serie->codigoSgs(),
            ));
        }

        return $texto;
    }
}
