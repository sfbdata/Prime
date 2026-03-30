<?php

namespace App\EventSubscriber;

use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Intercepta requisições e bloqueia acesso a módulos não autorizados.
 *
 * Mapeamento rota-prefixo → módulo (slug usado no PermissionChecker):
 *   /pasta*        → pastas
 *   /clientes*     → clientes
 *   /processos*    → processos
 *   /tarefas*      → tarefas
 *   /agenda*       → agenda
 *   /servicedesk*  → servicedesk
 *   /pre-cadastro* → precadastros
 */
class ModuleAccessSubscriber implements EventSubscriberInterface
{
    /** @var array<string, array{module: string, label: string}> */
    private const ROUTE_MODULE_MAP = [
        '/pasta'        => ['module' => 'pastas',       'label' => 'Pastas'],
        '/clientes'     => ['module' => 'clientes',     'label' => 'Clientes'],
        '/processos'    => ['module' => 'processos',    'label' => 'Processos'],
        '/tarefas'      => ['module' => 'tarefas',      'label' => 'Tarefas'],
        '/agenda'       => ['module' => 'agenda',       'label' => 'Agenda'],
        '/servicedesk'  => ['module' => 'servicedesk',  'label' => 'Service Desk'],
        '/pre-cadastro' => ['module' => 'precadastros', 'label' => 'Pré-Cadastros'],
    ];

    public function __construct(
        private readonly Security $security,
        private readonly PermissionChecker $permissionChecker,
        private readonly Environment $twig,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        $pathInfo = $event->getRequest()->getPathInfo();

        foreach (self::ROUTE_MODULE_MAP as $prefix => $config) {
            if (!str_starts_with($pathInfo, $prefix)) {
                continue;
            }

            if ($this->permissionChecker->canAccessModule($user, $config['module'])) {
                return;
            }

            $html = $this->twig->render('access_request/module_denied.html.twig', [
                'moduloLabel' => $config['label'],
            ]);

            $event->setResponse(new Response($html, Response::HTTP_FORBIDDEN));
            return;
        }
    }
}
