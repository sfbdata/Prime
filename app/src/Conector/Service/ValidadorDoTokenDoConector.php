<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Entity\TokenDoConector;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * O validador do token opaco do `/mcp` (`docs/specs/mcp-remoto-oauth.md`, 3.2, seção 4, regra 3, e 5.1), que o
 * `AuthorizationMiddleware` do SDK chama. Um juiz só: o autenticador do firewall e o middleware leem o mesmo resultado,
 * guardado por requisição, e só o SDK formata o 401 e o 403.
 *
 * ESQUELETO do commit 0 do PR 5: a assinatura é o contrato; o bloco A completa o corpo (hash do token, tipo acesso,
 * validade, `resource`, conferência da concessão) e as dependências.
 */
final class ValidadorDoTokenDoConector implements AuthorizationTokenValidatorInterface, ResetInterface
{
    public function validate(string $accessToken): AuthorizationResult
    {
        throw new \LogicException('PR 5 bloco A');
    }

    /** O token aceito nesta requisição, ou null se nenhum foi aceito. */
    public function tokenValidado(): ?TokenDoConector
    {
        throw new \LogicException('PR 5 bloco A');
    }

    /** O motivo da recusa nesta requisição, de lista fechada, para o log `mcp`; null se não houve recusa. */
    public function motivoDaRecusa(): ?string
    {
        throw new \LogicException('PR 5 bloco A');
    }

    /** Zera o resultado guardado da requisição. Não lança: roda em todo `kernel.reset`. */
    public function reset(): void
    {
    }
}
