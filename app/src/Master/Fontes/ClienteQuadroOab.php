<?php

declare(strict_types=1);

namespace App\Master\Fontes;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Baixa a página do Quadro da Advocacia (Conselho Federal da OAB). A OAB atualiza a página todos os
 * dias às 00:01; o comando `app:master:atualizar-fontes-publicas` roda uma vez por dia.
 */
final class ClienteQuadroOab
{
    public const URL = 'https://www.oab.org.br/institucionalconselhofederal/quadroadvogados';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @throws FonteIndisponivel
     */
    public function baixarPagina(): string
    {
        try {
            $resposta = $this->httpClient->request('GET', self::URL, [
                'headers'      => ['Accept' => 'text/html', 'User-Agent' => 'JusPrime/1.0 (+https://bluejus.com.br)'],
                'timeout'      => 10.0,
                'max_duration' => 30.0,
            ]);

            $status = $resposta->getStatusCode();
            if ($status >= 400) {
                throw new FonteIndisponivel(sprintf('OAB respondeu HTTP %d.', $status));
            }

            return $resposta->getContent();
        } catch (ExceptionInterface $e) {
            throw new FonteIndisponivel('Falha ao acessar a OAB: ' . $e->getMessage(), 0, $e);
        }
    }
}
