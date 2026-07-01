<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

// Prioridade 6: roda depois do firewall (8) e do gate de Termos de Uso (7),
// garantindo que o aceite da plataforma venha antes da seleção de escritório.
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final class TenantContextValidatorListener
{
    private const ROTAS_IGNORADAS = [
        'app_login',
        'app_logout',
        'tenant_selecionar',
        // Criar escritório não exige tenant ativo — quem tem 0 escritórios precisa
        // alcançar esta tela para abrir o primeiro.
        'escritorio_criar',
        'admin_platform_dashboard',
        // Aceite dos Termos é da plataforma e independe de tenant — a tela precisa
        // ser alcançável antes da seleção de escritório (ver TermoAceiteListener).
        'termo_aceite',
        'termo_aceite_registrar',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly RouterInterface $router,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route', '');

        if ($this->deveIgnorar($route)) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (!$this->tenantContext->hasCurrentTenant()) {
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                return;
            }

            $event->setResponse(new RedirectResponse($this->router->generate('tenant_selecionar')));

            return;
        }

        $userTenant = $this->tenantContext->getCurrentUserTenant();

        if ($userTenant !== null && $userTenant->isActive()) {
            return;
        }

        $this->tenantContext->clearCurrentTenant();

        $event->getRequest()->getSession()->getFlashBag()->add(
            'warning',
            'Seu vínculo com este escritório foi removido. Selecione outro escritório.',
        );

        $event->setResponse(new RedirectResponse($this->router->generate('tenant_selecionar')));
    }

    private function deveIgnorar(string $route): bool
    {
        if (in_array($route, self::ROTAS_IGNORADAS, true)) {
            return true;
        }

        return str_starts_with($route, '_');
    }
}
