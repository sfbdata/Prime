<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\DTO\PedidoDeAutorizacao;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Exception\ErroOAuth;
use App\Conector\Service\RegistroDeClientes;
use App\Conector\Service\ServidorDeAutorizacao;

/**
 * Confere o pedido que o app de I.A. mandou ao `/oauth/authorize` (`docs/specs/mcp-remoto-oauth.md`, 3.2, 3.4, 5.1 e
 * 9.6), na ordem que a RFC 6749 (4.1.2.1) exige:
 *
 * 1. cliente e `redirect_uri` pelo {@see RegistroDeClientes}. Recusa lança {@see ClienteRecusado}, que NUNCA vira
 *    redirecionamento: com cliente ou retorno inválido, o servidor não pode confiar no `redirect_uri`;
 * 2. só depois disso, os erros do protocolo, que voltam ao app por redirecionamento ({@see ErroOAuth}):
 *    `response_type` diferente de `code`, PKCE sem S256 ou com desafio malformado, `resource` diferente do BlueJus
 *    (`invalid_target`).
 *
 * Quem recebe um {@see ErroOAuth} daqui pode redirecionar ao `redirect_uri` do pedido: ele já passou pelo registro.
 *
 * Escopos: só os que o servidor conhece, na ordem dele; desconhecido é ignorado (decisão 10 de 16/11). A leitura entra
 * sempre na tela; a gravação só se o app pediu E a pessoa marcar.
 */
final class ValidarPedidoDeAutorizacaoUseCase
{
    /** O desafio S256 é o SHA-256 em base64url sem `=`: sempre 43 caracteres (RFC 7636 4.2). */
    private const DESAFIO_S256 = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(
        private readonly RegistroDeClientes $registro,
    ) {
    }

    /**
     * @param array<mixed> $parametros a query do `/oauth/authorize`
     *
     * @throws ClienteRecusado sem redirecionar
     * @throws ErroOAuth       para redirecionar ao `redirect_uri` do pedido
     */
    public function executar(array $parametros): PedidoDeAutorizacao
    {
        $clientId = self::texto($parametros, 'client_id');
        $redirectUri = self::texto($parametros, 'redirect_uri');
        $this->exigirCliente($clientId, $redirectUri);

        self::exigirFluxoComCodigo($parametros);
        $desafio = self::exigirPkceS256($parametros);
        $resource = self::exigirResource($parametros);

        return new PedidoDeAutorizacao(
            (string) $clientId,
            (string) $redirectUri,
            self::texto($parametros, 'state'),
            $desafio,
            $resource,
            self::escoposConhecidos(self::texto($parametros, 'scope')),
        );
    }

    /** @throws ClienteRecusado */
    private function exigirCliente(?string $clientId, ?string $redirectUri): void
    {
        if ($clientId === null || $clientId === '') {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_CLIENT_ID_INVALIDO, null);
        }
        if ($redirectUri === null || $redirectUri === '') {
            throw new ClienteRecusado(ClienteRecusado::MOTIVO_REDIRECT_NAO_PERMITIDO, ClienteRecusado::hostDe($clientId));
        }

        $this->registro->resolver($clientId, $redirectUri);
    }

    /** @param array<mixed> $parametros */
    private static function exigirFluxoComCodigo(array $parametros): void
    {
        if (self::texto($parametros, 'response_type') !== 'code') {
            throw ErroOAuth::unsupportedResponseType();
        }
    }

    /** @param array<mixed> $parametros */
    private static function exigirPkceS256(array $parametros): string
    {
        if (self::texto($parametros, 'code_challenge_method') !== 'S256') {
            throw ErroOAuth::invalidRequest('O PKCE com o método S256 é obrigatório.');
        }

        $desafio = self::texto($parametros, 'code_challenge');
        if ($desafio === null || preg_match(self::DESAFIO_S256, $desafio) !== 1) {
            throw ErroOAuth::invalidRequest('O code_challenge do PKCE está ausente ou malformado.');
        }

        return $desafio;
    }

    /** @param array<mixed> $parametros */
    private static function exigirResource(array $parametros): string
    {
        if (self::texto($parametros, 'resource') !== ServidorDeAutorizacao::RESOURCE) {
            throw ErroOAuth::invalidTarget();
        }

        return ServidorDeAutorizacao::RESOURCE;
    }

    /** @return list<string> */
    private static function escoposConhecidos(?string $scope): array
    {
        $pedidos = preg_split('/ +/', trim((string) $scope), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_intersect(ServidorDeAutorizacao::ESCOPOS_SUPORTADOS, $pedidos));
    }

    /**
     * O parâmetro como texto; nulo quando falta ou não é texto (`client_id[]=...` não vira texto por acidente).
     *
     * @param array<mixed> $parametros
     */
    private static function texto(array $parametros, string $nome): ?string
    {
        $valor = $parametros[$nome] ?? null;

        return \is_string($valor) ? $valor : null;
    }
}
