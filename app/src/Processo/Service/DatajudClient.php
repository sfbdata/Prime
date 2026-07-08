<?php

namespace App\Processo\Service;

use App\Processo\Enum\MotivoFalhaDatajud;
use App\Processo\Exception\ConsultaDatajudException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DatajudClient
{
    private HttpClientInterface $httpClient;
    private string $apiKey;
    private string $baseUrl;
    private TribunalCnjResolver $tribunalResolver;

    public function __construct(
        HttpClientInterface $httpClient,
        string $datajudApiKey,
        string $datajudBaseUrl,
        TribunalCnjResolver $tribunalResolver
    ) {
        $this->httpClient = $httpClient;
        $this->apiKey = $datajudApiKey;
        $this->baseUrl = rtrim($datajudBaseUrl, '/');
        $this->tribunalResolver = $tribunalResolver;
    }

    /**
     * @throws \App\Processo\Exception\TribunalNaoIdentificadoException se o tribunal não puder
     *         ser derivado do número (formato inválido ou código desconhecido)
     * @throws ConsultaDatajudException se a consulta ao CNJ falhar (rede, timeout, indisponibilidade,
     *         limite, configuração, tribunal sem base pública ou resposta malformada)
     */
    public function searchByNumeroProcesso(string $numeroProcesso): array
    {
        if (trim($this->apiKey) === '') {
            throw new ConsultaDatajudException(MotivoFalhaDatajud::NaoConfigurado, 'DATAJUD_API_KEY nao configurada.');
        }

        // O tribunal é derivado do próprio número (padrão CNJ) — o usuário não informa a sigla.
        // Resolve antes de qualquer chamada externa: número fora do padrão lança aqui, não gasta rede.
        $tribunalAlias = $this->tribunalResolver->resolverAlias($numeroProcesso);

        $numeroProcesso = $this->normalizeNumeroProcessoCnj($numeroProcesso);

        $url = $this->baseUrl . '/api_publica_' . $tribunalAlias . '/_search';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'APIKey ' . $this->apiKey,
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'json' => [
                    'query' => [
                        'match' => [
                            'numeroProcesso' => $numeroProcesso,
                        ],
                    ],
                ],
            ]);

            // getStatusCode() aguarda os headers sem lançar em 4xx/5xx — permite classificar o motivo.
            $status = $response->getStatusCode();
            $this->garantirStatusOk($status);

            return $response->toArray(false);
        } catch (TimeoutExceptionInterface $e) {
            throw new ConsultaDatajudException(MotivoFalhaDatajud::Timeout, $e->getMessage(), $e);
        } catch (DecodingExceptionInterface | \JsonException $e) {
            throw new ConsultaDatajudException(MotivoFalhaDatajud::RespostaInvalida, $e->getMessage(), $e);
        } catch (TransportExceptionInterface $e) {
            throw new ConsultaDatajudException(MotivoFalhaDatajud::Indisponivel, $e->getMessage(), $e);
        }
    }

    private function garantirStatusOk(int $status): void
    {
        if ($status < 400) {
            return;
        }

        $motivo = match (true) {
            $status === 429                    => MotivoFalhaDatajud::LimiteExcedido,
            $status === 401 || $status === 403 => MotivoFalhaDatajud::NaoConfigurado,
            // Índice do tribunal inexistente na base pública do CNJ (Elasticsearch: index_not_found).
            $status === 404                    => MotivoFalhaDatajud::TribunalSemBasePublica,
            $status >= 500                     => MotivoFalhaDatajud::Indisponivel,
            default                            => MotivoFalhaDatajud::RespostaInvalida,
        };

        throw new ConsultaDatajudException($motivo, 'HTTP ' . $status);
    }

    private function normalizeNumeroProcessoCnj(string $numeroProcesso): string
    {
        return preg_replace('/\D+/', '', $numeroProcesso) ?? '';
    }
}
