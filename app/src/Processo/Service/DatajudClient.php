<?php

namespace App\Processo\Service;

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
     */
    public function searchByNumeroProcesso(string $numeroProcesso): array
    {
        if (trim($this->apiKey) === '') {
            throw new \RuntimeException('DATAJUD_API_KEY nao configurada.');
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

            $result = $response->toArray(false);
            // Corrigir encoding UTF-8 dos dados
            $result = $this->fixUtf8Encoding($result);
            return $result;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Corrige encoding UTF-8 dos dados retornados pela API (removido - causa problemas)
     */
    private function fixUtf8Encoding($data)
    {
        // Apenas retorna os dados como estão - conversão aqui estava corrompendo outros campos
        return $data;
    }

    private function normalizeNumeroProcessoCnj(string $numeroProcesso): string
    {
        return preg_replace('/\D+/', '', $numeroProcesso) ?? '';
    }
}
