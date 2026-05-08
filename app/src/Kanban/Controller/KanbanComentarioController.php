<?php

declare(strict_types=1);

namespace App\Kanban\Controller;

use App\Entity\Auth\User;
use App\Kanban\DTO\CriarComentarioInput;
use App\Kanban\Repository\KanbanCardRepository;
use App\Kanban\Repository\KanbanComentarioRepository;
use App\Kanban\UseCase\AtualizarComentarioUseCase;
use App\Kanban\UseCase\CriarComentarioUseCase;
use App\Kanban\UseCase\ExcluirComentarioUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/kanban')]
final class KanbanComentarioController extends AbstractController
{
    public function __construct(
        private readonly CriarComentarioUseCase $criarComentario,
        private readonly AtualizarComentarioUseCase $atualizarComentario,
        private readonly ExcluirComentarioUseCase $excluirComentario,
        private readonly KanbanCardRepository $cardRepository,
        private readonly KanbanComentarioRepository $comentarioRepository,
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/card/{cardId}/comentario', name: 'kanban_comentario_criar', methods: ['POST'], requirements: ['cardId' => '\d+'])]
    public function criar(int $cardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        $card = $this->cardRepository->findPorTenantEId($cardId, $user->getTenant());
        if ($card === null || !$card->getBoard()->temAcesso($user)) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Card não encontrado.'], 404);
        }

        $data  = $request->toArray();
        $input = new CriarComentarioInput(conteudo: (string) ($data['conteudo'] ?? ''));

        if (trim(strip_tags($input->conteudo)) === '') {
            return $this->json(['sucesso' => false, 'mensagem' => 'Comentário não pode estar vazio.'], 422);
        }

        $output = $this->criarComentario->executar($input, $card, $user);
        $html   = $this->renderView('kanban/_partials/_comentario.html.twig', ['comentario' => $output]);

        return $this->json(['sucesso' => true, 'html' => $html, 'id' => $output->id]);
    }

    #[Route('/comentario/{id}', name: 'kanban_comentario_editar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        $comentario = $this->comentarioRepository->findPorTenantEId($id, $user->getTenant());
        if ($comentario === null) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Comentário não encontrado.'], 404);
        }

        $data  = $request->toArray();
        $input = new CriarComentarioInput(conteudo: (string) ($data['conteudo'] ?? ''));

        $output = $this->atualizarComentario->executar($comentario, $input, $user);

        return $this->json(['sucesso' => true, 'conteudo' => $output->conteudo]);
    }

    #[Route('/comentario/{id}/excluir', name: 'kanban_comentario_excluir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function excluir(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('kanban_comentario_excluir_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Token inválido.'], 403);
        }

        $comentario = $this->comentarioRepository->findPorTenantEId($id, $user->getTenant());
        if ($comentario === null) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Comentário não encontrado.'], 404);
        }

        $this->excluirComentario->executar($comentario, $user);

        return $this->json(['sucesso' => true]);
    }

    private function assertAccess(User $user): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'kanban')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Kanban.');
        }
    }
}
