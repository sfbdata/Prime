<?php

declare(strict_types=1);

namespace App\Conector\DTO;

use App\Conector\Exception\ErroOAuth;

/**
 * O corpo do `POST /oauth/token` (RFC 6749, 4.1.3 e 6; RFC 7636, 4.5; RFC 8707, 2.2), já lido do formulário
 * `application/x-www-form-urlencoded`. Campo ausente ou vazio vira nulo; campo repetido em forma de lista
 * (`code[]=...`) é pedido malformado ({@see deFormulario()} lança `invalid_request`).
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
