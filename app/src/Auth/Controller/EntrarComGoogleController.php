<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Exception\EntradaPeloGoogleRecusadaException;
use App\Auth\Infra\GoogleLoginApi;
use App\Security\GoogleAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Ida do "Entrar com o Google" (spec entrar-com-google, entrega 1). Guarda na sessão o `state`, o verificador do PKCE
 * e o `nonce`, e leva ao Google. "Manter conectado" só vale com o token do próprio botão da tela (um link forjado
 * não liga o lembrar-me). A volta é do `GoogleAuthenticator`, no firewall.
 */
final class EntrarComGoogleController extends AbstractController
{
    private const LIMITE = 'Muitas tentativas de entrar com o Google. Tente de novo em alguns minutos.';

    public function __construct(
        private readonly GoogleLoginApi $google,
        private readonly RateLimiterFactoryInterface $loginGoogleLimiter,
    ) {
    }

    #[Route('/login/google', name: 'app_login_google', methods: ['GET'])]
    public function ir(Request $request): RedirectResponse
    {
        if (!$this->google->ligado()) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->loginGoogleLimiter->create('ip-' . $request->getClientIp())->consume()->isAccepted()) {
            return $this->voltarComErro($request, self::LIMITE);
        }
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verificador = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $request->getSession()->set(GoogleAuthenticator::SESSAO, [
            'state' => $state,
            'nonce' => $nonce,
            'verificador' => $verificador,
            'manter' => '1' === $request->query->getString('manter') && $this->isCsrfTokenValid('login_google', $request->query->getString('_token')),
        ]);
        $desafio = rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');

        return $this->redirect($this->google->urlDeAutorizacao($state, $desafio, $nonce, $this->generateUrl('app_login_google_volta', [], UrlGeneratorInterface::ABSOLUTE_URL)));
    }

    /** O firewall (`GoogleAuthenticator`) responde antes; chegar aqui é volta sem sessão de ida. */
    #[Route('/login/google/callback', name: 'app_login_google_volta', methods: ['GET'])]
    public function voltar(Request $request): RedirectResponse
    {
        return $this->voltarComErro($request, EntradaPeloGoogleRecusadaException::FALHOU);
    }

    private function voltarComErro(Request $request, string $mensagem): RedirectResponse
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, new CustomUserMessageAuthenticationException($mensagem));

        return $this->redirectToRoute('app_login');
    }
}
