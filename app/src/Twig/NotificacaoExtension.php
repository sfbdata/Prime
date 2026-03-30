<?php

namespace App\Twig;

use App\Entity\Auth\User;
use App\Repository\AccessRequestRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig Extension que fornece notificações globalmente para todos os templates.
 */
class NotificacaoExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly NotificacaoService $notificacaoService,
        private readonly Security $security,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function getGlobals(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [
                'notificacoes' => [],
                'notificacoesCount' => 0,
                'accessRequestsPendingCount' => 0,
            ];
        }

        $pendingCount = 0;
        if ($this->permissionChecker->canAdminister($user, 'admin.access_requests.approve') && $user->getTenant() !== null) {
            $pendingCount = count($this->accessRequestRepository->findPendingByTenant($user->getTenant()));
        }

        return [
            'notificacoes' => $this->notificacaoService->getNotificacoesNaoLidas($user, 10),
            'notificacoesCount' => $this->notificacaoService->contarNaoLidas($user),
            'accessRequestsPendingCount' => $pendingCount,
        ];
    }
}
