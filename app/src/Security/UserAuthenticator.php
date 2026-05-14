<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Auth\User;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

final class UserAuthenticator extends AbstractLoginFormAuthenticator
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly EntityManagerInterface $em,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly TenantContext $tenantContext,
    ) {}

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('email', '');

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($request->request->get('password', '')),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->router->generate('app_login');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return new RedirectResponse($this->router->generate('expediente_index'));
        }

        $tenants = $this->userTenantRepository->findActiveByUser($user);

        if (count($tenants) === 1) {
            $userTenant = $tenants[0];
            $this->tenantContext->setCurrentTenant($userTenant->getTenant()->getId());
            $userTenant->setLastLoginAt(new \DateTimeImmutable());
            $this->em->flush();

            return new RedirectResponse($this->router->generate('expediente_index'));
        }

        $this->em->flush();

        if (count($tenants) === 0 && in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return new RedirectResponse($this->router->generate('admin_platform_dashboard'));
        }

        return new RedirectResponse($this->router->generate('tenant_selecionar'));
    }
}
