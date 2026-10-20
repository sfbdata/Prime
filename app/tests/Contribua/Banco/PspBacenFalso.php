<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Banco;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * PSP falso da API Pix do BACEN para o MockHttpClient (Inter, BB e "Outro"), com as respostas dos exemplos
 * da especificação (bacen.github.io/pix-api, v2.x):
 * - token OAuth2 client_credentials: `{access_token, token_type, expires_in, scope}`;
 * - `PUT /cob/{txid}`: a cobrança com `status ATIVA`, `location` e `pixCopiaECola`;
 * - `GET /cob/{txid}`: `CONCLUIDA` com `pix[0]` (endToEndId, valor, horario) quando paga;
 * - `GET /cob?inicio&fim`: a lista (`parametros`, `cobs`);
 * - erros no formato RFC 7807 (`type`, `title`, `status`, `detail`, `violacoes`).
 * Registra cada pedido (método, URL, opções) para conferir mTLS, token, app key e txid.
 */
final class PspBacenFalso
{
    /** @var list<array{metodo: string, url: string, opcoes: array<string, mixed>}> */
    public array $pedidos = [];
    /** @var array<string, array<string, mixed>> txid => cobrança */
    public array $cobs = [];
    public bool $recusarToken = false;
    /** Força a resposta da criação (500 = banco fora; 400 = recusa do pedido). */
    public ?int $statusDaCriacao = null;
    /** Força a resposta da consulta leve (Testar conexão). */
    public ?int $statusDaLista = null;
    public int $tokensExpirados = 0;
    public int $tokensEmitidos = 0;

    public function cliente(): MockHttpClient
    {
        return new MockHttpClient($this->responder(...));
    }

    /** @param array<string, mixed> $opcoes */
    public function responder(string $metodo, string $url, array $opcoes): MockResponse
    {
        $this->pedidos[] = ['metodo' => $metodo, 'url' => $url, 'opcoes' => $opcoes];
        $caminho = (string) parse_url($url, \PHP_URL_PATH);
        if (str_ends_with($caminho, '/token')) {
            return $this->token();
        }
        if ($this->tokensExpirados > 0 && 'Bearer tok-1' === $this->cabecalho($opcoes, 'authorization')) {
            --$this->tokensExpirados;

            return $this->json(['type' => 'https://pix.bcb.gov.br/api/v2/error/AcessoNegado', 'title' => 'Não Autorizado', 'status' => 401, 'detail' => 'Token expirado.'], 401);
        }
        if (str_ends_with($caminho, '/cob')) {
            return null !== $this->statusDaLista
                ? $this->json(['title' => 'Acesso negado', 'status' => $this->statusDaLista, 'detail' => 'Escopo insuficiente.'], $this->statusDaLista)
                : $this->json(['parametros' => ['inicio' => '2026-10-19T00:00:00Z', 'fim' => '2026-10-19T01:00:00Z', 'paginacao' => ['paginaAtual' => 0, 'itensPorPagina' => 1, 'quantidadeDePaginas' => 0, 'quantidadeTotalDeItens' => 0]], 'cobs' => []]);
        }
        $txid = basename($caminho);
        if ('PUT' === $metodo) {
            return $this->criar($txid, $opcoes);
        }

        return $this->consultar($txid);
    }

    public function pagar(string $txid, string $valor = '20.00'): void
    {
        $this->cobs[$txid]['status'] = 'CONCLUIDA';
        $this->cobs[$txid]['pix'] = [['endToEndId' => 'E00038166202610191430abcdef12345', 'txid' => $txid, 'valor' => $valor, 'horario' => '2026-10-19T14:30:00.000-03:00', 'infoPagador' => 'Apoio']];
    }

    /** @param array<string, mixed> $opcoes */
    public function cabecalho(array $opcoes, string $nome): ?string
    {
        foreach ((array) ($opcoes['headers'] ?? []) as $h) {
            if (is_string($h) && str_starts_with(strtolower($h), $nome . ':')) {
                return trim(substr($h, strlen($nome) + 1));
            }
        }
        foreach ((array) ($opcoes['normalized_headers'][$nome] ?? []) as $h) {
            return trim(substr((string) $h, strlen($nome) + 1));
        }

        return null;
    }

    private function token(): MockResponse
    {
        if ($this->recusarToken) {
            return $this->json(['error' => 'invalid_client', 'error_description' => 'Client authentication failed'], 401);
        }
        ++$this->tokensEmitidos;

        return $this->json(['access_token' => 'tok-' . $this->tokensEmitidos, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'cob.write cob.read pix.read webhook.read webhook.write']);
    }

    /** @param array<string, mixed> $opcoes */
    private function criar(string $txid, array $opcoes): MockResponse
    {
        if (null !== $this->statusDaCriacao) {
            return $this->json(['type' => 'https://pix.bcb.gov.br/api/v2/error/CobOperacaoInvalida', 'title' => 'Cobrança inválida.', 'status' => $this->statusDaCriacao, 'detail' => 'A requisição que busca alterar ou criar uma cobrança não respeita o schema.', 'violacoes' => [['razao' => 'falha simulada ' . $this->statusDaCriacao, 'propriedade' => 'cob.valor.original']]], $this->statusDaCriacao);
        }
        if (isset($this->cobs[$txid])) {
            return $this->json(['type' => 'https://pix.bcb.gov.br/api/v2/error/CobOperacaoInvalida', 'title' => 'Cobrança inválida.', 'status' => 400, 'detail' => 'O campo cob.txid já está em uso.'], 400);
        }
        $corpo = (array) json_decode((string) ($opcoes['body'] ?? '{}'), true);
        $this->cobs[$txid] = $corpo + [
            'txid' => $txid,
            'revisao' => 0,
            'status' => 'ATIVA',
            'loc' => ['id' => 789, 'location' => 'pix.example.com/qr/v2/2353c790eefb11eaadc10242ac120002', 'tipoCob' => 'cob'],
            'location' => 'pix.example.com/qr/v2/2353c790eefb11eaadc10242ac120002',
            'pixCopiaECola' => '00020101021226830014br.gov.bcb.pix2561pix.example.com/qr/v2/2353c790eefb11eaadc10242ac120002520400005303986540520.005802BR5913BLUEJUS LTDA6008BRASILIA62070503***6304E2CA',
        ];
        $this->cobs[$txid]['calendario'] = ['criacao' => '2026-10-19T13:00:00.000Z'] + (array) ($corpo['calendario'] ?? []);

        return $this->json($this->cobs[$txid], 201);
    }

    private function consultar(string $txid): MockResponse
    {
        if (!isset($this->cobs[$txid])) {
            return $this->json(['type' => 'https://pix.bcb.gov.br/api/v2/error/CobNaoEncontrado', 'title' => 'Cobrança não encontrada.', 'status' => 404, 'detail' => 'Não existe cobrança com o txid informado.'], 404);
        }

        return $this->json($this->cobs[$txid]);
    }

    /** @param array<string, mixed> $dados */
    private function json(array $dados, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($dados), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
