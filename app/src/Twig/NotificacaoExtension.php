<?php

namespace App\Twig;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
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
                'notificacoesPessoaisCount' => 0,
                'notificacoesGestaoCount' => 0,
                'podeVerGestao' => false,
            ];
        }

        return [
            // Lista inicial (aba "Minhas"); a aba "Gestão" carrega via AJAX ao abrir
            'notificacoes' => $this->notificacaoService->getNotificacoesNaoLidas($user, Notificacao::CATEGORIA_PESSOAL, 10),
            'notificacoesCount' => $this->notificacaoService->contarNaoLidas($user),
            'notificacoesPessoaisCount' => $this->notificacaoService->contarNaoLidas($user, Notificacao::CATEGORIA_PESSOAL),
            'notificacoesGestaoCount' => $this->notificacaoService->contarNaoLidas($user, Notificacao::CATEGORIA_GESTAO),
            'podeVerGestao' => $this->notificacaoService->podeVerGestao($user),
        ];
    }
}
