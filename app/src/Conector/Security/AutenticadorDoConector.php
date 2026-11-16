<?php

declare(strict_types=1);

namespace App\Conector\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * O autenticador do firewall `conector_mcp` (`docs/specs/mcp-remoto-oauth.md`, 8): bearer, sem sessão.
 *
 * ESQUELETO do commit 0 do PR 5, só para o container compilar com o firewall: não aceita requisição nenhuma
 * ({@see supports()} devolve false). O bloco A completa: aceita só `Authorization: Bearer`, pergunta ao validador do
 * token e, na falha, devolve null para o SDK formatar o 401.
 */
final class AutenticadorDoConector extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        throw new \LogicException('PR 5 bloco A');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
