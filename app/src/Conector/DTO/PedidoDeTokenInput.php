<?php

declare(strict_types=1);

namespace App\Conector\DTO;

use App\Conector\Exception\ErroOAuth;

/**
 * O corpo do `POST /oauth/token` (RFC 6749, 4.1.3 e 6; RFC 7636, 4.5; RFC 8707, 2.2), já lido do formulário
 * `application/x-www-form-urlencoded`. Campo ausente ou vazio vira nulo; campo repetido em forma de lista
 * (`code[]=...`) é pedido malformado ({@see deFormulario()} lança `invalid_request`), e o campo repetido simples
 * (`code=a&code=b`), que o PHP reduziria ao último valor, também ({@see exigirCamposUnicos()}, RFC 6749 3.2).
 *
 * Os campos valem para os dois tipos de concessão: `authorization_code` usa `code`, `redirectUri`, `codeVerifier` e
 * `resource`; `refresh_token` usa `refreshToken` e, se vier, `resource`. `clientId` é exigido nos dois (cliente público,
 * `token_endpoint_auth_methods_supported: ["none"]`). O `scope` do pedido de renovação é ignorado: a resposta diz o
 * escopo da concessão.
 */
final class PedidoDeTokenInput
{
    public const GRANT_CODIGO = 'authorization_code';
    public const GRANT_RENOVACAO = 'refresh_token';

    private const CAMPOS = ['grant_type', 'code', 'redirect_uri', 'client_id', 'code_verifier', 'refresh_token', 'resource'];

    public function __construct(
        public readonly ?string $grantType,
        public readonly ?string $clientId,
        public readonly ?string $code = null,
        public readonly ?string $redirectUri = null,
        public readonly ?string $codeVerifier = null,
        public readonly ?string $refreshToken = null,
        public readonly ?string $resource = null,
    ) {
    }

    /**
     * RFC 6749 3.2: "Request and response parameters MUST NOT be included more than once". Confere no corpo cru, porque o
     * PHP já ficou só com o último valor de `code=a&code=b` quando o formulário chega decodificado. As chaves são
     * decodificadas como no formulário (`+` vira espaço, `%xx` vira o caractere), então `code` e `%63ode` são o mesmo
     * campo.
     *
     * @throws ErroOAuth `invalid_request` se algum campo aparece mais de uma vez
     */
    public static function exigirCamposUnicos(string $corpoCru): void
    {
        $vistos = [];
        foreach (explode('&', $corpoCru) as $par) {
            if ($par === '') {
                continue;
            }
            $chave = self::nomeComoOPhp(urldecode(explode('=', $par, 2)[0]));
            if (isset($vistos[$chave])) {
                throw ErroOAuth::invalidRequest();
            }
            $vistos[$chave] = true;
        }
    }

    /**
     * O nome do campo como o PHP o grava no `$_POST`: sem os espaços do começo, com `.` e espaço trocados por `_`, e o
     * `[` sem par também; com `[...]`, vale o nome antes dele. Assim `grant.type` e `grant_type` contam como repetição.
     */
    private static function nomeComoOPhp(string $nome): string
    {
        $nome = ltrim($nome, ' ');
        $colchete = strpos($nome, '[');
        if ($colchete !== false) {
            $nome = str_contains(substr($nome, $colchete), ']')
                ? substr($nome, 0, $colchete)
                : substr_replace($nome, '_', $colchete, 1);
        }

        return strtr($nome, ['.' => '_', ' ' => '_']);
    }

    /**
     * @param array<mixed> $campos o `$request->request->all()`
     *
     * @throws ErroOAuth `invalid_request` se um campo conhecido vier como lista
     */
    public static function deFormulario(array $campos): self
    {
        $valores = [];
        foreach (self::CAMPOS as $nome) {
            $valor = $campos[$nome] ?? null;
            if ($valor !== null && !\is_string($valor)) {
                throw ErroOAuth::invalidRequest();
            }
            $valores[$nome] = $valor === null || $valor === '' ? null : $valor;
        }

        return new self(
            $valores['grant_type'],
            $valores['client_id'],
            $valores['code'],
            $valores['redirect_uri'],
            $valores['code_verifier'],
            $valores['refresh_token'],
            $valores['resource'],
        );
    }
}
