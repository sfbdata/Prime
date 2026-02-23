<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class DatajudClient
{
    private HttpClientInterface $httpClient;
    private string $apiKey;
    private string $baseUrl;

    public function __construct(HttpClientInterface $httpClient, string $datajudApiKey, string $datajudBaseUrl)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $datajudApiKey;
        $this->baseUrl = rtrim($datajudBaseUrl, '/');
    }

    public function searchByNumeroProcesso(string $tribunalAlias, string $numeroProcesso): array
    {
        if (trim($this->apiKey) === '') {
            throw new \RuntimeException('DATAJUD_API_KEY nao configurada.');
        }

        $numeroProcesso = $this->normalizeNumeroProcessoCnj($numeroProcesso);
        if ($numeroProcesso === '') {
            throw new \InvalidArgumentException('Numero do processo invalido.');
        }

        $url = $this->baseUrl . '/api_publica_' . trim($tribunalAlias, '/') . '/_search';

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
