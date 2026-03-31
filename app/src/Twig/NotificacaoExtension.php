<?php

namespace App\Twig;

use App\Entity\Auth\User;
use App\Service\NotificacaoService;
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
        private readonly Security $security
    ) {
    }

    public function getGlobals(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [
                'notificacoes' => [],
                'notificacoesCount' => 0,
            ];
        }

        return [
            'notificacoes' => $this->notificacaoService->getNotificacoesNaoLidas($user, 10),
            'notificacoesCount' => $this->notificacaoService->contarNaoLidas($user),
        ];
    }
}
