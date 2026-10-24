<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class UserAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly EntradaDoUsuario $entrada,
    ) {}

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('email', '');
        // Devolve o e-mail ao campo quando a senha erra (o `last_username` da tela de entrada).
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($request->request->get('password', '')),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    /**
     * Sem sessão, pedido de dados (o `fetch` do sino, XHR, quem pede JSON) recebe 401 em JSON. Quem responde
     * primeiro é o `SessaoVencidaEmPedidoDeDadosListener`, antes do firewall gravar o destino; isto aqui é a rede
     * de segurança. Não apaga o `target_path`: ele pode ser o destino que outra aba de navegação gravou.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (!PedidoDeNavegacao::eNavegacao($request)) {
            return PedidoDeNavegacao::respostaDeSessaoVencida();
        }

        return parent::start($request, $authException);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->router->generate('app_login');
    }

    /** Toda entrada pelo formulário passa pelo véu `/login/entrando`; o destino é do `EntradaDoUsuario`. */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->entrada->redirecionar($request, $token, $firewallName);
    }
}
