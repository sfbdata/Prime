<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Selo;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A fábrica do {@see SeladorDePdf} em `config/services.yaml` (spec assinatura-documentos-etapa-8, seção 2). Sem
 * `ASSINATURA_VALIDADOR_URL` ou sem `ASSINATURA_VALIDADOR_TOKEN` (vazios = não configurado, etapa 6, seção 2), vale o
 * {@see SeladorDePdfNaoConfigurado} e nenhum pedido sai; com os dois, o {@see HttpSeladorDePdf}.
 *
 * Nunca lança: o contêiner sobe com as variáveis vazias (a suíte não tem validador).
 */
final readonly class EscolhaDoSeladorDePdf
{
    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
        private string $url,
        private string $token,
    ) {
    }

    public function selador(): SeladorDePdf
    {
        if (trim($this->url, HttpSeladorDePdf::ESPACOS) === '' || trim($this->token, HttpSeladorDePdf::ESPACOS) === '') {
            return new SeladorDePdfNaoConfigurado();
        }

        return new HttpSeladorDePdf($this->http, $this->logger, $this->url, $this->token);
    }
}
