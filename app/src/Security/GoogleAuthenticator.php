<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\DTO\IdentidadeDoGoogle;
use App\Auth\Exception\EntradaPeloGoogleRecusadaException;
use App\Auth\Infra\GoogleLoginApi;
use App\Auth\Service\ContaDoGoogle;
use App\Auth\UseCase\LigarContaAoGoogleUseCase;
use App\Entity\Auth\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * A volta do "Entrar com o Google" (spec entrar-com-google, entrega 1). Estende `AbstractAuthenticator` puro: com dois
 * autenticadores de formulário o container não sobe (entry_point). Confere o `state` da sessão, troca o código pela
 * identidade conferida (PKCE, `nonce`, `aud`, `iss`, `exp`, e-mail confirmado), acha a conta que já existe e deixa as
 * barreiras do firewall (UserChecker) decidirem. Só depois do sucesso a conta é ligada ao Google e o e-mail
 * confirmado; o destino é o mesmo da senha (`EntradaDoUsuario`). "Manter conectado" vale quando veio do próprio botão.
 */
final class GoogleAuthenticator extends AbstractAuthenticator
{
    public const SESSAO = 'login_google_oauth';
    private const ROTA = 'app_login_google_volta';
    private const IDENTIDADE = '_identidade_do_google';

    public function __construct(
        private readonly GoogleLoginApi $google,
        private readonly ContaDoGoogle $contas,
        private readonly LigarContaAoGoogleUseCase $ligar,
        private readonly EntradaDoUsuario $entrada,
        private readonly RouterInterface $router,
        private readonly TokenStorageInterface $tokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::ROTA === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $ida = $this->idaGuardada($request);
        if ('' !== $request->query->getString('error') || '' === $request->query->getString('code')) {
            throw new CustomUserMessageAuthenticationException(EntradaPeloGoogleRecusadaException::CANCELADA);
        }
        try {
            $identidade = $this->google->identidade($request->query->getString('code'), $ida['verificador'], $this->urlDeVolta(), $ida['nonce']);
            // O e-mail do Google volta ao campo da tela, inclusive quando não há conta com ele.
            $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $identidade->email);
            $usuario = $this->contas->localizar($identidade);
        } catch (EntradaPeloGoogleRecusadaException $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage(), [], 0, $e);
        }
        $request->attributes->set(self::IDENTIDADE, $identidade);

        return new SelfValidatingPassport(
            new UserBadge((string) $usuario->getUserIdentifier(), static fn (): User => $usuario),
            // Valor explícito nos dois casos: sem ele o Symfony leria `_remember_me` da URL de volta.
            [new RememberMeBadge(['_remember_me' => $ida['manter'] ? 'on' : '0'])],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $usuario = $token->getUser();
        $identidade = $request->attributes->get(self::IDENTIDADE);
        if ($usuario instanceof User && $identidade instanceof IdentidadeDoGoogle) {
            try {
                $this->ligar->executar($usuario, $identidade);
            } catch (\Throwable $e) {
                // Corrida rara (outra conta Google ligou esta conta agora): não fica logado sem a ligação valer.
                return $this->desfazerEntrada($request, $e);
            }
        }

        return $this->entrada->redirecionar($request, $token, $firewallName);
    }

    /** Volta à tela de entrada com a mensagem no lugar do erro do formulário. */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->router->generate('app_login'));
    }

    private function desfazerEntrada(Request $request, \Throwable $e): Response
    {
        $this->logger->error('Entrar com o Google: a ligação da conta falhou depois da entrada', ['exception' => $e]);
        $this->tokens->setToken(null);
        $request->getSession()->invalidate();

        return $this->onAuthenticationFailure($request, new CustomUserMessageAuthenticationException(EntradaPeloGoogleRecusadaException::FALHOU));
    }

    /**
     * O que a ida guardou na sessão (uma vez só) e o `state` que volta do Google, comparado em tempo constante.
     *
     * @return array{verificador: string, nonce: string, manter: bool}
     */
    private function idaGuardada(Request $request): array
    {
        $guardado = $request->hasSession() ? $request->getSession()->remove(self::SESSAO) : null;
        if (!is_array($guardado) || !hash_equals((string) ($guardado['state'] ?? ''), $request->query->getString('state'))) {
            throw new CustomUserMessageAuthenticationException(EntradaPeloGoogleRecusadaException::FALHOU);
        }

        return ['verificador' => (string) ($guardado['verificador'] ?? ''), 'nonce' => (string) ($guardado['nonce'] ?? ''), 'manter' => true === ($guardado['manter'] ?? false)];
    }

    private function urlDeVolta(): string
    {
        return $this->router->generate(self::ROTA, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
