<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\Service\DestinoPosLogin;
use App\Entity\Auth\User;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class UserAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly RouterInterface $router,
        private readonly EntityManagerInterface $em,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly TenantContext $tenantContext,
        private readonly DestinoPosLogin $destinoPosLogin,
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

    /**
     * Toda entrada pelo formulário passa pelo véu `/login/entrando?para=<destino>`, que revalida o
     * destino (spec login-desenho-10, comportamento 2). O destino é o que o usuário tentava abrir
     * antes do login (`target_path`), quando ele é deste site e o caso de escritório permite.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $alvo = $this->lerAlvo($request, $firewallName);
        $user = $token->getUser();

        $destino = $user instanceof User
            ? $this->destinoDoUsuario($user, $alvo)
            : ($alvo ?? $this->router->generate('expediente_index'));

        return new RedirectResponse($this->router->generate('app_login_entrando', ['para' => $destino]));
    }

    /**
     * Lê e limpa o `target_path` da sessão; só sobra destino que passou pelo `DestinoPosLogin`.
     */
    private function lerAlvo(Request $request, string $firewallName): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        $bruto = $this->getTargetPath($session, $firewallName);
        $this->removeTargetPath($session, $firewallName);

        return $this->destinoPosLogin->validar($bruto, $request);
    }

    private function destinoDoUsuario(User $user, ?string $alvo): string
    {
        $tenants = $this->userTenantRepository->findActiveByUser($user);

        if (count($tenants) === 1) {
            $userTenant = $tenants[0];
            $this->tenantContext->setCurrentTenant($userTenant->getTenant()->getId());
            $userTenant->setLastLoginAt(new \DateTimeImmutable());
            $this->em->flush();

            return $alvo ?? $this->router->generate('expediente_index');
        }

        $this->em->flush();

        if (count($tenants) === 0 && in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return $alvo ?? $this->router->generate('admin_platform_dashboard');
        }

        // Vários escritórios (ou nenhum, sem ser Master): a escolha do escritório vem antes e o
        // alvo se perde, como já era antes do véu. Levar o alvo pela seleção é decisão à parte.
        return $this->router->generate('tenant_selecionar');
    }
}
