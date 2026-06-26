<?php

namespace App\Controller;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Repository\NotificacaoRepository;
use App\Service\NotificacaoService;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/notificacoes')]
class NotificacaoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    private const PER_PAGE = 20;

    public function __construct(
        private readonly NotificacaoService $notificacaoService,
        private readonly NotificacaoRepository $notificacaoRepository
    ) {
    }

    /**
     * Lista todas as notificações do usuário
     */
    #[Route('', name: 'notificacao_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $podeVerGestao = $this->notificacaoService->podeVerGestao($usuario);

        $abaAtiva = ($request->query->get('tab') === Notificacao::CATEGORIA_GESTAO && $podeVerGestao)
            ? Notificacao::CATEGORIA_GESTAO
            : Notificacao::CATEGORIA_PESSOAL;

        $page = max(1, $request->query->getInt('page', 1));

        // Monta itens + metadados de paginação de uma categoria. O ?page= só
        // vale para a aba ativa; a outra aba sempre renderiza a primeira página.
        $montarPaginacao = function (string $categoria) use ($usuario, $page, $abaAtiva): array {
            $total      = $this->notificacaoRepository->countByUsuario($usuario, $categoria);
            $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
            $atual      = $categoria === $abaAtiva ? min($page, $totalPages) : 1;

            return [
                'itens' => $this->notificacaoRepository->findPaginadasByUsuario($usuario, $categoria, $atual, self::PER_PAGE),
                'meta'  => [
                    'current_page' => $atual,
                    'per_page'     => self::PER_PAGE,
                    'total_items'  => $total,
                    'total_pages'  => $totalPages,
                ],
            ];
        };

        $pessoal = $montarPaginacao(Notificacao::CATEGORIA_PESSOAL);
        $gestao  = $podeVerGestao ? $montarPaginacao(Notificacao::CATEGORIA_GESTAO) : ['itens' => [], 'meta' => null];

        return $this->render('notificacao/index.html.twig', [
            'notificacoesPessoais' => $pessoal['itens'],
            'paginacaoPessoal'     => $pessoal['meta'],
            'notificacoesGestao'   => $gestao['itens'],
            'paginacaoGestao'      => $gestao['meta'],
            'podeVerGestao'        => $podeVerGestao,
            'abaAtiva'             => $abaAtiva,
        ]);
    }

    /**
     * Marca uma notificação como lida (via AJAX)
     */
    #[Route('/{id}/ler', name: 'notificacao_marcar_lida', methods: ['POST'])]
    public function marcarComoLida(Notificacao $notificacao, Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        // Verifica se a notificação pertence ao usuário
        if ($notificacao->getUsuario()->getId() !== $usuario->getId()) {
            return new JsonResponse(['error' => 'Acesso negado'], 403);
        }

        // CSRF após o guard de posse (o 403 de posse tem prioridade e fica testável).
        $this->validarCsrfAjax($request);

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

        $categoria = $request->request->get('categoria');
        $categoria = in_array($categoria, [Notificacao::CATEGORIA_PESSOAL, Notificacao::CATEGORIA_GESTAO], true)
            ? $categoria
            : null;

        $this->notificacaoService->marcarTodasComoLidas($usuario, $categoria);

        $this->addFlash('success', 'Todas as notificações foram marcadas como lidas.');

        return $this->redirectToRoute('notificacao_index', $categoria !== null ? ['tab' => $categoria] : []);
    }

    /**
     * Exclui as notificações selecionadas do usuário (via AJAX)
     */
    #[Route('/excluir', name: 'notificacao_excluir', methods: ['POST'])]
    public function excluir(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        if (!$this->isCsrfTokenValid('excluir_notificacoes', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Token CSRF inválido.'], 403);
        }

        // ids[] vindos do form-data; normaliza para inteiros válidos
        $ids = array_values(array_filter(
            array_map(
                static fn ($v) => filter_var($v, FILTER_VALIDATE_INT),
                $request->request->all('ids')
            ),
            static fn ($v) => $v !== false
        ));

        $excluidas = $this->notificacaoService->excluir($usuario, $ids);

        return new JsonResponse(['success' => true, 'excluidas' => $excluidas]);
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
    public function listaDropdown(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        $categoria = $request->query->get('categoria') === Notificacao::CATEGORIA_GESTAO
            ? Notificacao::CATEGORIA_GESTAO
            : Notificacao::CATEGORIA_PESSOAL;

        // Só quem recebe notificações de gestão acessa essa aba
        if ($categoria === Notificacao::CATEGORIA_GESTAO && !$this->notificacaoService->podeVerGestao($usuario)) {
            return new JsonResponse(['html' => '', 'count' => 0], 403);
        }

        $notificacoes = $this->notificacaoService->getNotificacoesNaoLidas($usuario, $categoria, 10);
        $count = $this->notificacaoService->contarNaoLidas($usuario, $categoria);

        $html = '';
        if (count($notificacoes) > 0) {
            foreach ($notificacoes as $notif) {
                $link = $notif->getUrl()
                    ?? ($notif->getTarefa() ? "/tarefas/{$notif->getTarefa()->getId()}" : '#');
                $link = htmlspecialchars($link, ENT_QUOTES);
                $icone = htmlspecialchars($notif->getIcone(), ENT_QUOTES);
                $titulo = htmlspecialchars($notif->getTitulo(), ENT_QUOTES);
                $mensagem = (string) $notif->getMensagem();
                $tempo = htmlspecialchars($notif->getTempoRelativo(), ENT_QUOTES);
                $unreadClass = !$notif->isLida() ? ' notif-unread' : '';

                $html .= "<a href=\"{$link}\" class=\"dropdown-item notif-item{$unreadClass}\">";
                $html .= "<i class=\"bi {$icone} notif-icon\"></i>";
                $html .= '<div class="notif-body">';
                $html .= "<div class=\"notif-title\">{$titulo}</div>";
                // Não duplica a mensagem quando é igual ao título (ponto/servicedesk gravam ambos iguais)
                if ($mensagem !== '' && $mensagem !== $notif->getTitulo()) {
                    $html .= '<div class="notif-msg">' . htmlspecialchars($mensagem, ENT_QUOTES) . '</div>';
                }
                $html .= "<div class=\"notif-time\">{$tempo}</div>";
                $html .= '</div></a>';
                $html .= '<div class="dropdown-divider m-0"></div>';
            }
        } else {
            $html .= '<a href="#" class="dropdown-item text-center text-muted py-3">';
            $html .= '<i class="bi bi-check-circle me-2"></i> Nenhuma notificação';
            $html .= '</a>';
        }

        return new JsonResponse([
            'html' => $html,
            'count' => $count,
        ]);
    }
}
