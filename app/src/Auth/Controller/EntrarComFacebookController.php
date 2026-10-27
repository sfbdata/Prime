<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Exception\EntradaPeloFacebookRecusadaException;
use App\Auth\Infra\FacebookLoginApi;
use App\Security\FacebookAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Ida do "Continuar com Facebook" (spec login-cupom-e-facebook, PR B). Guarda na sessão o `state` e leva ao Facebook. A
 * volta é do `FacebookAuthenticator`, no firewall. Sem o app da Meta no servidor (`FACEBOOK_APP_ID` e
 * `FACEBOOK_APP_SECRET`) o botão some da tela e as duas rotas respondem 404; diferente do Google, não há aviso de "não
 * configurado".
 */
final class EntrarComFacebookController extends AbstractController
{
    private const LIMITE = 'Muitas tentativas de continuar com o Facebook. Tente de novo em alguns minutos.';
    /** Intenção do CSRF do botão da tela. */
    public const CSRF = 'login_facebook';

    public function __construct(
        private readonly FacebookLoginApi $facebook,
        private readonly RateLimiterFactoryInterface $loginFacebookLimiter,
    ) {
    }

    #[Route('/login/facebook', name: 'app_login_facebook', methods: ['GET'])]
    public function ir(Request $request): RedirectResponse
    {
        if (!$this->facebook->ligado()) {
            throw new NotFoundHttpException();
        }
        if (!$this->loginFacebookLimiter->create('ip-' . $request->getClientIp())->consume()->isAccepted()) {
            return $this->voltarComErro($request, self::LIMITE);
        }
        // Link forjado ou sem o token do botão da tela: recusa, sem ir ao Facebook.
        if (!$this->isCsrfTokenValid(self::CSRF, $request->query->getString('_token'))) {
            return $this->voltarComErro($request, EntradaPeloFacebookRecusadaException::FALHOU, true);
        }
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(FacebookAuthenticator::SESSAO, [
            'state' => $state,
            'manter' => '1' === $request->query->getString('manter'),
        ]);

        return $this->redirect($this->facebook->urlDeAutorizacao($state, $this->generateUrl('app_login_facebook_volta', [], UrlGeneratorInterface::ABSOLUTE_URL)));
    }

    /** O firewall (`FacebookAuthenticator`) responde antes; chegar aqui é volta sem sessão de ida, ou sem credencial. */
    #[Route('/login/facebook/callback', name: 'app_login_facebook_volta', methods: ['GET'])]
    public function voltar(Request $request): RedirectResponse
    {
        if (!$this->facebook->ligado()) {
            throw new NotFoundHttpException();
        }

        return $this->voltarComErro($request, EntradaPeloFacebookRecusadaException::FALHOU);
    }

    private function voltarComErro(Request $request, string $mensagem, bool $aoCadastro = false): RedirectResponse
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, new CustomUserMessageAuthenticationException($mensagem));

        return $this->redirectToRoute('app_login', $aoCadastro ? ['abrir' => 'cadastro'] : []);
    }
}
