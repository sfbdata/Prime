<?php

declare(strict_types=1);

namespace App\Djen\Service;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Enum\MotivoFalhaDjen;
use App\Djen\Exception\ConsultaDjenException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente da API pública do DJEN (Diário de Justiça Eletrônico Nacional / CNJ).
 *
 * Consulta é 100% pública (sem chave/token). Depende do contrato HttpClientInterface para poder ser
 * testado com MockHttpClient. Espelha a classificação de falha do DatajudClient (transporte + status),
 * traduzindo tudo para ConsultaDjenException + MotivoFalhaDjen.
 */
final class DjenClient implements DjenClientInterface
{
    private const CAMINHO_COMUNICACAO = '/api/v1/comunicacao';
    private const TIMEOUT_SEGUNDOS = 30.0;
    private const MAX_DURATION_SEGUNDOS = 60.0;

    private string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $djenBaseUrl,
    ) {
        $this->baseUrl = rtrim($djenBaseUrl, '/');
    }

    /**
     * Consulta uma página de comunicações no DJEN.
     *
     * @throws ConsultaDjenException se a consulta falhar (config, rede, timeout, limite, sobrecarga
     *         ou resposta malformada)
     */
    public function consultarComunicacoes(ConsultaComunicacoesQuery $query): RespostaComunicacoes
    {
        if (trim($this->baseUrl) === '') {
            throw new ConsultaDjenException(MotivoFalhaDjen::NaoConfigurado, 'DJEN_BASE_URL nao configurada.');
        }

        $url = $this->baseUrl . self::CAMINHO_COMUNICACAO;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => $query->toQueryParams(),
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'JusPrime/1.0 (+https://bluejus.com.br)',
                ],
                'timeout' => self::TIMEOUT_SEGUNDOS,
                'max_duration' => self::MAX_DURATION_SEGUNDOS,
            ]);

            // getStatusCode() aguarda os headers sem lançar em 4xx/5xx — permite classificar o motivo.
            $status = $response->getStatusCode();
            $this->garantirStatusOk($status);

            $data = $response->toArray(false);
        } catch (TimeoutExceptionInterface $e) {
            throw new ConsultaDjenException(MotivoFalhaDjen::Timeout, $e->getMessage(), $e);
        } catch (DecodingExceptionInterface | \JsonException $e) {
            throw new ConsultaDjenException(MotivoFalhaDjen::RespostaInvalida, $e->getMessage(), $e);
        } catch (TransportExceptionInterface $e) {
            throw new ConsultaDjenException(MotivoFalhaDjen::Indisponivel, $e->getMessage(), $e);
        }

        // Envelope de erro do DJEN (ex.: 200/500 com {"status":"error","message":"...muito ocupado..."}).
        if (($data['status'] ?? null) !== 'success') {
            throw new ConsultaDjenException(
                MotivoFalhaDjen::SistemaOcupado,
                'Envelope DJEN status=' . (is_scalar($data['status'] ?? null) ? (string) $data['status'] : 'desconhecido'),
            );
        }

        return new RespostaComunicacoes(
            (int) ($data['count'] ?? 0),
            $this->normalizarItens($data['items'] ?? null),
        );
    }

    private function garantirStatusOk(int $status): void
    {
        if ($status < 400) {
            return;
        }

        $motivo = match (true) {
            $status === 429                    => MotivoFalhaDjen::LimiteExcedido,
            // O DJEN devolve 500 "O sistema está muito ocupado" sob carga — transitório.
            $status === 500                    => MotivoFalhaDjen::SistemaOcupado,
            $status === 401 || $status === 403 => MotivoFalhaDjen::Indisponivel,
            $status > 500                      => MotivoFalhaDjen::Indisponivel,
            default                            => MotivoFalhaDjen::RespostaInvalida,
        };

        throw new ConsultaDjenException($motivo, 'HTTP ' . $status);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizarItens(mixed $itens): array
    {
        if (!is_array($itens)) {
            return [];
        }

        $lista = [];
        foreach ($itens as $item) {
            if (is_array($item)) {
                $lista[] = $item;
            }
        }

        return $lista;
    }
}
