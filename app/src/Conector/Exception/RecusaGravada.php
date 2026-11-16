<?php

declare(strict_types=1);

namespace App\Conector\Exception;

/**
 * Uma recusa do `/oauth/token` que já mudou o banco e precisa ficar gravada: a concessão revogada (código reapresentado,
 * renovação reutilizada ou recusada, barreira que caiu na renovação). Lançada dentro da
 * {@see \App\Conector\Service\TransacaoDoToken}, que grava e CONFIRMA a transação e só então relança o {@see $erro}
 * (`invalid_grant`) para o app. Sem ela, a recusa desfaria a própria revogação (`docs/specs/mcp-remoto-oauth.md`, 9.1 e
 * 9.2: "renovação que falha em qualquer item tem a concessão revogada e responde invalid_grant").
 */
final class RecusaGravada extends \RuntimeException
{
    public function __construct(public readonly ErroOAuth $erro, ?\Throwable $causa = null)
    {
        parent::__construct($erro->getMessage(), 0, $causa);
    }
}
