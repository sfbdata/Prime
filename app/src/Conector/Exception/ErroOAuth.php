<?php

declare(strict_types=1);

namespace App\Conector\Exception;

/**
 * Um erro do protocolo OAuth (RFC 6749 4.1.2.1 e 5.2, RFC 8707 e RFC 7636) que vira resposta ao app de I.A.: no
 * `/oauth/token`, o JSON `{"error", "error_description"}`; no `/oauth/authorize`, os parâmetros `error`,
 * `error_description`, `state` e `iss` no redirecionamento (`docs/specs/mcp-remoto-oauth.md`, 3.2 e 9.6).
 *
 * {@see $codigo} é o código da norma (lista fechada abaixo). {@see $descricao} é texto FIXO, escolhido por quem lança
 * entre textos literais: nunca leva dado do pedido (client_id, redirect, código, token), porque vai ao app e ao log.
 *
 * Erro de cliente ou de `redirect_uri` no `/oauth/authorize` NÃO é este: é {@see ClienteRecusado}, que nunca
 * redireciona (RFC 6749 4.1.2.1).
 */
final class ErroOAuth extends \RuntimeException
{
    public const INVALID_REQUEST = 'invalid_request';
    public const INVALID_CLIENT = 'invalid_client';
    public const INVALID_GRANT = 'invalid_grant';
    public const INVALID_TARGET = 'invalid_target';
    public const INVALID_SCOPE = 'invalid_scope';
    public const UNSUPPORTED_GRANT_TYPE = 'unsupported_grant_type';
    public const UNSUPPORTED_RESPONSE_TYPE = 'unsupported_response_type';
    public const ACCESS_DENIED = 'access_denied';

    private function __construct(public readonly string $codigo, public readonly string $descricao)
    {
        parent::__construct($codigo . ': ' . $descricao);
    }

    public static function invalidRequest(string $descricao = 'O pedido está incompleto ou malformado.'): self
    {
        return new self(self::INVALID_REQUEST, $descricao);
    }

    public static function invalidClient(string $descricao = 'O aplicativo não foi reconhecido.'): self
    {
        return new self(self::INVALID_CLIENT, $descricao);
    }

    public static function invalidGrant(string $descricao = 'A autorização não vale mais. Conecte de novo.'): self
    {
        return new self(self::INVALID_GRANT, $descricao);
    }

    public static function invalidTarget(string $descricao = 'O recurso pedido não é o do BlueJus.'): self
    {
        return new self(self::INVALID_TARGET, $descricao);
    }

    public static function invalidScope(string $descricao = 'O escopo pedido não é válido.'): self
    {
        return new self(self::INVALID_SCOPE, $descricao);
    }

    public static function unsupportedGrantType(string $descricao = 'Tipo de concessão não aceito.'): self
    {
        return new self(self::UNSUPPORTED_GRANT_TYPE, $descricao);
    }

    public static function unsupportedResponseType(string $descricao = 'Só o fluxo com código de autorização é aceito.'): self
    {
        return new self(self::UNSUPPORTED_RESPONSE_TYPE, $descricao);
    }

    public static function accessDenied(string $descricao = 'O acesso não foi autorizado.'): self
    {
        return new self(self::ACCESS_DENIED, $descricao);
    }

    /**
     * O status HTTP no `/oauth/token` (RFC 6749 5.2): 401 para `invalid_client`, 400 para o resto. No
     * `/oauth/authorize` o erro vai no redirecionamento e este número não se aplica.
     */
    public function statusHttp(): int
    {
        return $this->codigo === self::INVALID_CLIENT ? 401 : 400;
    }

    /**
     * O corpo da resposta (JSON do `/oauth/token`) ou os parâmetros do redirecionamento de erro do `/oauth/authorize`,
     * sem `state` e `iss`, que quem redireciona acrescenta.
     *
     * @return array{error: string, error_description: string}
     */
    public function paraResposta(): array
    {
        return ['error' => $this->codigo, 'error_description' => $this->descricao];
    }
}
