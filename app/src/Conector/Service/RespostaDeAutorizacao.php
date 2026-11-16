<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Exception\ErroOAuth;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * O redirecionamento de volta ao app de I.A. no fim do `/oauth/authorize` (RFC 6749 4.1.2 e 4.1.2.1, RFC 9207;
 * `docs/specs/mcp-remoto-oauth.md`, 3.2 e 9.6): `code` no sucesso, `error` e `error_description` no erro, e SEMPRE o
 * `state` do pedido (quando veio) e o `iss` com o texto exato de {@see ServidorDeAutorizacao::ISSUER}, também no erro.
 *
 * Só recebe `redirect_uri` que já passou pelo registro de clientes: erro de cliente ou de retorno nunca chega aqui
 * (vira página de erro, {@see \App\Conector\Exception\ClienteRecusado}). A resposta não vai para cache e não manda
 * Referer (o código está na URL).
 */
final class RespostaDeAutorizacao
{
    private function __construct()
    {
    }

    public static function sucesso(string $redirectUri, string $codigo, ?string $state): RedirectResponse
    {
        return self::redirecionar($redirectUri, ['code' => $codigo], $state);
    }

    public static function erro(string $redirectUri, ErroOAuth $erro, ?string $state): RedirectResponse
    {
        return self::redirecionar($redirectUri, $erro->paraResposta(), $state);
    }

    /** @param array<string, string> $parametros */
    private static function redirecionar(string $redirectUri, array $parametros, ?string $state): RedirectResponse
    {
        if ($state !== null) {
            $parametros['state'] = $state;
        }
        $parametros['iss'] = ServidorDeAutorizacao::ISSUER;

        // O `redirect_uri` pode trazer query própria (RFC 6749 3.1.2): ela fica, e os nossos parâmetros vão depois.
        $separador = str_contains($redirectUri, '?') ? '&' : '?';
        $resposta = new RedirectResponse($redirectUri . $separador . http_build_query($parametros, '', '&', \PHP_QUERY_RFC3986));
        $resposta->headers->set('Cache-Control', 'no-store');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }
}
