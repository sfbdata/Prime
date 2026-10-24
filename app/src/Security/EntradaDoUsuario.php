<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\Service\DestinoPosLogin;
use App\Entity\Auth\User;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Para onde vai quem acabou de entrar, por senha ou pelo Google (uma regra só, spec entrar-com-google seção 4):
 * toda entrada passa pelo véu `/login/entrando?para=<destino>`, que revalida o destino (spec login-desenho-10,
 * comportamento 2). O destino é o que a pessoa tentava abrir antes do login (`target_path`), quando ele é deste site
 * e o caso de escritório permite.
 */
final class EntradaDoUsuario
{
    use TargetPathTrait;

    public function __construct(
        private readonly RouterInterface $router,
        private readonly EntityManagerInterface $em,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly TenantContext $tenantContext,
        private readonly BloqueioPorPedidoDeExclusao $bloqueioPorPedido,
        private readonly DestinoPosLogin $destinoPosLogin,
    ) {
    }

    public function redirecionar(Request $request, TokenInterface $token, string $firewallName): RedirectResponse
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
        // "Suspensa a pedido" (PR 5): o escritório barrado não é escolhido sozinho (sem laço com a barreira).
        $tenants = $this->bloqueioPorPedido->permitidos($this->userTenantRepository->findActiveByUser($user));

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
