<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\DTO\DocumentoCimd;
use App\Conector\Exception\ClienteRecusado;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as ExcecaoHttp;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A busca segura do documento CIMD (`docs/specs/mcp-remoto-oauth.md`, 3.4 e 9.5). Quem chama já conferiu que a URL é
 * https, tem caminho e o host está em {@see HostsDeClientePermitidos}; esta classe põe as outras travas:
 *
 * - {@see NoPrivateNetworkHttpClient} montado AQUI, sobre o cliente recebido, para nenhuma fiação tirar a trava: nome
 *   que resolve para IP privado, loopback, link-local ou reservado é recusado, também na conexão de fato;
 * - nenhum redirecionamento (`max_redirects: 0`): resposta diferente de 200, 30x inclusive, recusa;
 * - 5 s de espera e de duração total; corpo lido em pedaços, com teto de 16 KiB (acima, cancela e recusa);
 * - limitador `conector_cimd` por domínio, consumido só quando a busca vai acontecer.
 *
 * Falha de rede, de status ou de limite: `documento_indisponivel` ou `limite_de_busca`; corpo grande ou documento
 * inválido: `documento_invalido` ({@see DocumentoCimd}).
 */
final class BuscaDoDocumentoCimd
{
    public const TETO_EM_BYTES = 16384;
    public const TEMPO_EM_SEGUNDOS = 5.0;

    private readonly HttpClientInterface $http;

    /**
     * @param array<string, string> $resolucaoDeNomes opção `resolve` do HttpClient (host => IP). Vazia em produção; os
     *                                                testes fixam o IP para não depender de DNS. O IP fixado passa pela
     *                                                mesma trava de rede privada.
     */
    public function __construct(
        HttpClientInterface $httpClient,
        private readonly RateLimiterFactoryInterface $conectorCimdLimiter,
        private readonly PoliticaDeCacheCimd $politicaDeCache,
        private readonly ClockInterface $relogio,
        private readonly array $resolucaoDeNomes = [],
    ) {
        $this->http = new NoPrivateNetworkHttpClient($httpClient);
    }

    /** @throws ClienteRecusado */
    public function buscar(string $url, string $host): DocumentoCimd
    {
        if (!$this->conectorCimdLimiter->create($host)->consume()->isAccepted()) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_LIMITE_DE_BUSCA, $host);
        }

        try {
            $resposta = $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'max_redirects' => 0,
                'timeout' => self::TEMPO_EM_SEGUNDOS,
                'max_duration' => self::TEMPO_EM_SEGUNDOS,
                'resolve' => $this->resolucaoDeNomes,
            ]);
            $corpo = $this->lerComTeto($resposta, $host);
            $cabecalhos = $resposta->getHeaders(false);
        } catch (ExcecaoHttp $e) {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL, $host, $e);
        }

        $agora = $this->relogio->now();

        return DocumentoCimd::doCorpo($corpo, $url, $this->politicaDeCache->validoAte($cabecalhos, $agora));
    }

    /**
     * Lê o corpo em pedaços e para no teto. O status vem no primeiro pedaço: diferente de 200 cancela sem ler o corpo.
     *
     * @throws ExcecaoHttp
     */
    private function lerComTeto(ResponseInterface $resposta, string $host): string
    {
        $corpo = '';
        foreach ($this->http->stream($resposta) as $pedaco) {
            if ($pedaco->isFirst()) {
                $this->exigirStatusETamanho($resposta, $host);
            }
            $corpo .= $pedaco->getContent();
            if (\strlen($corpo) > self::TETO_EM_BYTES) {
                $resposta->cancel();

                throw new ClienteRecusado(ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO, $host);
            }
        }

        return $corpo;
    }

    /** @throws ExcecaoHttp */
    private function exigirStatusETamanho(ResponseInterface $resposta, string $host): void
    {
        if ($resposta->getStatusCode() !== 200) {
            $resposta->cancel();

            throw new ClienteRecusado(ClienteRecusado::MOTIVO_DOCUMENTO_INDISPONIVEL, $host);
        }

        $tamanho = $resposta->getHeaders(false)['content-length'][0] ?? null;
        if ($tamanho !== null && (int) $tamanho > self::TETO_EM_BYTES) {
            $resposta->cancel();

            throw new ClienteRecusado(ClienteRecusado::MOTIVO_DOCUMENTO_INVALIDO, $host);
        }
    }
}
