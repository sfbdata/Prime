<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\Exception\EntradaPeloFacebookRecusadaException;
use App\Auth\Infra\FacebookLoginApi;
use App\Auth\Service\CadastroPeloFacebook;
use App\Auth\Service\CadastroPeloGoogle;
use App\Auth\Service\ContaDoFacebook;
use App\Auth\Service\PoliticaDeCadastro;
use App\Entity\Auth\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A volta do "Continuar com Facebook" (spec login-cupom-e-facebook, PR B). Como o do Google, estende
 * `AbstractAuthenticator` puro. Confere o `state` da sessão (uma vez só, `hash_equals`), troca o código pela identidade
 * e acha a conta SÓ pelo `facebook_id`: o Facebook não prova e-mail, então o e-mail dele nunca liga, loga nem confirma
 * conta nenhuma. As barreiras do firewall (UserChecker) decidem o resto: conta ligada e ainda não confirmada não entra
 * (volta com o aviso de confirmar o e-mail) e conta inativa é recusada.
 *
 * Sem conta ligada ao `id`: a identidade espera na sessão (`CadastroPeloFacebook`) e a pessoa volta à tela de entrada
 * com o "Criar conta" aberto, conectado pelo Facebook (o e-mail é sugestão no campo, confirmado pelo link).
 */
final class FacebookAuthenticator extends AbstractAuthenticator
{
    public const SESSAO = 'login_facebook_oauth';
    private const ROTA = 'app_login_facebook_volta';
    /** Marca da falha que não é erro: leva ao "Criar conta" conectado pelo Facebook. */
    private const IR_AO_CADASTRO = 'cadastro_pelo_facebook';

    public function __construct(
        private readonly FacebookLoginApi $facebook,
        private readonly ContaDoFacebook $contas,
        private readonly CadastroPeloFacebook $cadastroPeloFacebook,
        private readonly EntradaDoUsuario $entrada,
        private readonly RouterInterface $router,
        private readonly PoliticaDeCadastro $politicaDeCadastro,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    public function supports(Request $request): bool
    {
        // Sem credencial a rota é 404 (o controller); o autenticador nem tenta.
        return self::ROTA === $request->attributes->get('_route') && $this->facebook->ligado();
    }

    public function authenticate(Request $request): Passport
    {
        $manter = $this->idaGuardada($request);
        if ('' !== $request->query->getString('error') || '' !== $request->query->getString('error_reason') || '' === $request->query->getString('code')) {
            throw new CustomUserMessageAuthenticationException(EntradaPeloFacebookRecusadaException::CANCELADA);
        }
        $identidade = null;
        try {
            $identidade = $this->facebook->identidade($request->query->getString('code'), $this->urlDeVolta());
            $usuario = $this->contas->localizar($identidade);
        } catch (EntradaPeloFacebookRecusadaException $e) {
            if (EntradaPeloFacebookRecusadaException::SEM_CONTA === $e->getMessage() && null !== $identidade) {
                // Cadastro fechado ao público (spec login-config-cadastro, item 6): a identidade nem é guardada e a
                // pessoa volta ao login com o aviso da política.
                if (!$this->politicaDeCadastro->publicoAberto()) {
                    throw new CustomUserMessageAuthenticationException($this->tradutor->trans(PoliticaDeCadastro::chaveDoAviso($this->politicaDeCadastro->atual())), [], 0, $e);
                }
                // Só um provedor espera o cadastro por vez; a identidade vem da sessão no POST, nunca do navegador.
                CadastroPeloGoogle::esquecer($request->getSession());
                $this->cadastroPeloFacebook->guardar($request->getSession(), $identidade);

                throw new CustomUserMessageAuthenticationException(self::IR_AO_CADASTRO, [], 0, $e);
            }
            throw new CustomUserMessageAuthenticationException($e->getMessage(), [], 0, $e);
        }

        return new SelfValidatingPassport(
            new UserBadge((string) $usuario->getUserIdentifier(), static fn (): User => $usuario),
            // Valor explícito nos dois casos: sem ele o Symfony leria `_remember_me` da URL de volta.
            [new RememberMeBadge(['_remember_me' => $manter ? 'on' : '0'])],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        return $this->entrada->redirecionar($request, $token, $firewallName);
    }

    /** Volta à tela de entrada com a mensagem no lugar do erro do formulário. */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (self::IR_AO_CADASTRO === $exception->getMessageKey()) {
            return new RedirectResponse($this->router->generate('app_login', ['abrir' => 'cadastro']));
        }
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->router->generate('app_login'));
    }

    /**
     * Confere o `state` que volta do Facebook contra o da ida (consumido uma vez, comparado em tempo constante) e
     * devolve se a ida pediu "manter conectado".
     */
    private function idaGuardada(Request $request): bool
    {
        $guardado = $request->hasSession() ? $request->getSession()->remove(self::SESSAO) : null;
        $esperado = is_array($guardado) ? (string) ($guardado['state'] ?? '') : '';
        if ('' === $esperado || !hash_equals($esperado, $request->query->getString('state'))) {
            throw new CustomUserMessageAuthenticationException(EntradaPeloFacebookRecusadaException::FALHOU);
        }

        return true === ($guardado['manter'] ?? false);
    }

    private function urlDeVolta(): string
    {
        return $this->router->generate(self::ROTA, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
