<?php

declare(strict_types=1);

namespace App\Conector\DTO;

use App\Conector\Exception\ClienteRecusado;
use App\Conector\Service\ServidorDeAutorizacao;

/**
 * O pedido do `/oauth/authorize` já conferido (`docs/specs/mcp-remoto-oauth.md`, 3.2, 3.4 e 9.4): cliente e
 * `redirect_uri` aceitos pelo registro de clientes, PKCE S256, `resource` do BlueJus e os escopos conhecidos entre os
 * pedidos (o desconhecido é ignorado, decisão 10 de 16/11).
 *
 * Fica na SESSÃO entre o GET e o POST da tela ({@see \App\Conector\Service\PedidoDeAutorizacaoNaSessao}); nada dele
 * volta do formulário. Por isso {@see paraSessao()} só tem textos e listas.
 */
final class PedidoDeAutorizacao
{
    /** Hosts de loopback (RFC 8252): app que roda no computador da pessoa, como o Claude Code. */
    private const LOOPBACK = ['127.0.0.1', 'localhost', '[::1]', '::1'];

    /**
     * @param list<string> $escoposPedidos os que o servidor conhece, na ordem de {@see ServidorDeAutorizacao::ESCOPOS_SUPORTADOS}
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly ?string $state,
        public readonly string $codeChallenge,
        public readonly string $resource,
        public readonly array $escoposPedidos,
    ) {
    }

    /** O app pediu `mcp:escrita`: a tela oferece a caixa, desmarcada (5.1). */
    public function pediuEscrita(): bool
    {
        return \in_array(ServidorDeAutorizacao::ESCOPO_ESCRITA, $this->escoposPedidos, true);
    }

    public function pediuRenovacao(): bool
    {
        return \in_array(ServidorDeAutorizacao::OFFLINE_ACCESS, $this->escoposPedidos, true);
    }

    /** O app que pede acesso, para a tela: o host do `client_id`, nunca o `client_name` autodeclarado (9.4). */
    public function hostDoCliente(): string
    {
        return ClienteRecusado::hostDe($this->clientId) ?? $this->clientId;
    }

    /** Para onde a pessoa volta depois de autorizar (3.4: "o consentimento mostra o host do redirect"). */
    public function hostDoRedirect(): string
    {
        $host = parse_url($this->redirectUri, \PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? strtolower($host) : $this->redirectUri;
    }

    /** O retorno vai a um endereço do próprio computador (Claude Code, CLIs): a tela avisa (9.4). */
    public function voltaParaLoopback(): bool
    {
        return \in_array($this->hostDoRedirect(), self::LOOPBACK, true);
    }

    /** @return array{client_id: string, redirect_uri: string, state: ?string, code_challenge: string, resource: string, escopos: list<string>} */
    public function paraSessao(): array
    {
        return [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $this->state,
            'code_challenge' => $this->codeChallenge,
            'resource' => $this->resource,
            'escopos' => $this->escoposPedidos,
        ];
    }

    /**
     * O pedido guardado por {@see paraSessao()}; nulo quando a forma não bate (sessão adulterada ou de outra versão).
     *
     * @param array<mixed> $dados
     */
    public static function daSessao(array $dados): ?self
    {
        foreach (['client_id', 'redirect_uri', 'code_challenge', 'resource'] as $chave) {
            if (!isset($dados[$chave]) || !\is_string($dados[$chave])) {
                return null;
            }
        }
        $state = $dados['state'] ?? null;
        $escopos = $dados['escopos'] ?? null;
        if (($state !== null && !\is_string($state)) || !\is_array($escopos) || !array_is_list($escopos)) {
            return null;
        }

        /** @var list<string> $escopos */
        $escopos = array_values(array_filter($escopos, 'is_string'));

        return new self($dados['client_id'], $dados['redirect_uri'], $state, $dados['code_challenge'], $dados['resource'], $escopos);
    }
}
