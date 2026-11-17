<?php

declare(strict_types=1);

namespace App\Conector\Security;

use App\Conector\Service\ValidadorDoTokenDoConector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * O autenticador do firewall `conector_mcp` (`docs/specs/mcp-remoto-oauth.md`, 8): bearer, sem sessão.
 *
 * Só entra com `Authorization: Bearer`. Pergunta ao {@see ValidadorDoTokenDoConector}, o mesmo que o
 * `AuthorizationMiddleware` do SDK usa depois (um juiz só, decisão 2 do PR 5): aceito, o firewall fica com a pessoa da
 * concessão do token; recusado, o firewall não responde nada ({@see onAuthenticationFailure()} devolve null) e a
 * requisição segue anônima até o SDK, que formata o 401 ou o 503 a partir do mesmo julgamento guardado.
 */
final class AutenticadorDoConector extends AbstractAuthenticator
{
    /** O mesmo critério do `AuthorizationMiddleware` do SDK para "veio um bearer". */
    private const ESQUEMA_BEARER = '/^Bearer(?: |$)/i';

    /** O b64token do cabeçalho (RFC 6750, 2.1), com a mesma expressão do SDK. */
    private const TOKEN_BEARER = '/^Bearer +([A-Za-z0-9\-._~+\/]+=*) *$/i';

    public function __construct(
        private readonly ValidadorDoTokenDoConector $validador,
    ) {
    }

    public function supports(Request $request): bool
    {
        return preg_match(self::ESQUEMA_BEARER, (string) $request->headers->get('Authorization', '')) === 1;
    }

    public function authenticate(Request $request): Passport
    {
        if (preg_match(self::TOKEN_BEARER, (string) $request->headers->get('Authorization', ''), $partes) !== 1) {
            throw new CustomUserMessageAuthenticationException('Cabeçalho Authorization malformado.');
        }

        $resultado = $this->validador->validate($partes[1]);
        $token = $this->validador->tokenValidado();
        if (!$resultado->isAllowed() || $token === null) {
            throw new CustomUserMessageAuthenticationException('Token do conector recusado.');
        }

        $pessoa = $token->getConcessao()->getUser();

        return new SelfValidatingPassport(new UserBadge($pessoa->getUserIdentifier(), static fn () => $pessoa));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /** Quem formata a recusa (401 com `WWW-Authenticate`, ou 503) é o SDK, pelo mesmo julgamento. */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
