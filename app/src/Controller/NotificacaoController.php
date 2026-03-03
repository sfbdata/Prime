<?php

namespace App\Controller;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Repository\NotificacaoRepository;
use App\Service\NotificacaoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/notificacoes')]
class NotificacaoController extends AbstractController
{
    public function __construct(
        private readonly NotificacaoService $notificacaoService,
        private readonly NotificacaoRepository $notificacaoRepository
    ) {
    }

    /**
     * Lista todas as notificações do usuário
     */
    #[Route('', name: 'notificacao_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $this->render('notificacao/index.html.twig', [
            'notificacoes' => $this->notificacaoRepository->findByUsuario($usuario, 100),
        ]);
    }

    /**
     * Marca uma notificação como lida (via AJAX)
     */
    #[Route('/{id}/ler', name: 'notificacao_marcar_lida', methods: ['POST'])]
    public function marcarComoLida(Notificacao $notificacao, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        // Verifica se a notificação pertence ao usuário
        if ($notificacao->getUsuario()->getId() !== $usuario->getId()) {
            return new JsonResponse(['error' => 'Acesso negado'], 403);
        }

        $this->notificacaoService->marcarComoLida($notificacao);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Marca todas as notificações como lidas
     */
    #[Route('/marcar-todas-lidas', name: 'notificacao_marcar_todas_lidas', methods: ['POST'])]
    public function marcarTodasComoLidas(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        if (!$this->isCsrfTokenValid('marcar_todas_lidas', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $this->notificacaoService->marcarTodasComoLidas($usuario);

        $this->addFlash('success', 'Todas as notificações foram marcadas como lidas.');

        return $this->redirectToRoute('notificacao_index');
    }

    /**
     * Retorna contador de notificações não lidas (para atualização via AJAX)
     */
    #[Route('/count', name: 'notificacao_count', methods: ['GET'])]
    public function count(): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return new JsonResponse([
            'count' => $this->notificacaoService->contarNaoLidas($usuario),
        ]);
    }

    /**
     * Retorna o HTML do dropdown de notificações atualizado (para AJAX)
     */
    #[Route('/lista-dropdown', name: 'notificacao_lista_dropdown', methods: ['GET'])]
    public function listaDropdown(): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        $notificacoes = $this->notificacaoService->getNotificacoesNaoLidas($usuario, 10);
        $count = $this->notificacaoService->contarNaoLidas($usuario);

        $html = '';
        if (count($notificacoes) > 0) {
            foreach ($notificacoes as $notif) {
                $link = $notif->getTarefa() ? "/tarefas/{$notif->getTarefa()->getId()}" : '#';
                $bgClass = !$notif->isLida() ? ' bg-light' : '';
                $html .= "<a href=\"{$link}\" class=\"dropdown-item{$bgClass}\">";
                $html .= '<div class="d-flex align-items-start">';
                $html .= "<i class=\"bi {$notif->getIcone()} me-2 mt-1\"></i>";
                $html .= '<div class="flex-grow-1">';
                $html .= "<div class=\"fw-semibold small\">{$notif->getTitulo()}</div>";
                $html .= "<div class=\"text-muted small text-truncate\" style=\"max-width: 250px;\">{$notif->getMensagem()}</div>";
                $html .= "<div class=\"text-muted smaller\">{$notif->getTempoRelativo()}</div>";
                $html .= '</div></div></a>';
                $html .= '<div class="dropdown-divider"></div>';
            }
        } else {
            $html .= '<a href="#" class="dropdown-item text-center text-muted">';
            $html .= '<i class="bi bi-check-circle me-2"></i> Nenhuma notificação';
            $html .= '</a>';
        }

        return new JsonResponse([
            'html' => $html,
            'count' => $count,
        ]);
    }
}
