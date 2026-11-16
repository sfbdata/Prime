<?php

declare(strict_types=1);

namespace App\Conector\DTO;

/**
 * O par emitido no `/oauth/token` (RFC 6749, 5.1). Os valores do token de acesso e da renovação só existem aqui, a
 * caminho do app de I.A.; o banco guarda só o SHA-256 deles.
 *
 * `scope` sai sempre (`docs/specs/mcp-remoto-oauth.md`, 5.1, item 1, e decisão 10 do planejamento do PR 3): são os
 * escopos da concessão, separados por espaço. `expiresIn` é o que falta, em segundos, para o token de acesso vencer,
 * sem passar do fim da concessão.
 */
final class RespostaDeTokenOutput
{
    public const TOKEN_TYPE = 'Bearer';

    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresIn,
        public readonly string $scope,
    ) {
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string} */
    public function paraResposta(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => self::TOKEN_TYPE,
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'scope' => $this->scope,
        ];
    }
}
