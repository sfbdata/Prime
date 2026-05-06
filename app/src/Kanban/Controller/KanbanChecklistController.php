<?php

declare(strict_types=1);

namespace App\Kanban\Controller;

use App\Entity\Auth\User;
use App\Kanban\DTO\AdicionarItemChecklistInput;
use App\Kanban\DTO\CriarChecklistInput;
use App\Kanban\Repository\KanbanCardRepository;
use App\Kanban\Repository\KanbanChecklistItemRepository;
use App\Kanban\Repository\KanbanChecklistRepository;
use App\Kanban\UseCase\AdicionarItemChecklistUseCase;
use App\Kanban\UseCase\CriarChecklistUseCase;
use App\Kanban\UseCase\ExcluirChecklistUseCase;
use App\Kanban\UseCase\MarcarItemChecklistUseCase;
use App\Kanban\UseCase\RemoverItemChecklistUseCase;
use App\Service\PermissionChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/kanban')]
final class KanbanChecklistController extends AbstractController
{
    public function __construct(
        private readonly CriarChecklistUseCase $criarChecklist,
        private readonly ExcluirChecklistUseCase $excluirChecklist,
        private readonly AdicionarItemChecklistUseCase $adicionarItem,
        private readonly MarcarItemChecklistUseCase $marcarItem,
        private readonly RemoverItemChecklistUseCase $removerItem,
        private readonly KanbanCardRepository $cardRepository,
        private readonly KanbanChecklistRepository $checklistRepository,
        private readonly KanbanChecklistItemRepository $itemRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('/card/{cardId}/checklist', name: 'kanban_checklist_criar', methods: ['POST'], requirements: ['cardId' => '\d+'])]
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
        $input = new CriarChecklistInput(titulo: trim((string) ($data['titulo'] ?? 'Checklist')));
        $output = $this->criarChecklist->executar($input, $card);

        return $this->json(['sucesso' => true, 'checklist' => ['id' => $output->id, 'titulo' => $output->titulo]]);
    }

    #[Route('/checklist/{id}/excluir', name: 'kanban_checklist_excluir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function excluir(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('kanban_checklist_excluir_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Token inválido.'], 403);
        }

        $checklist = $this->checklistRepository->find($id);
        if ($checklist === null || $checklist->getCard()->getBoard()->getTenant() !== $user->getTenant()) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Checklist não encontrada.'], 404);
        }

        $this->excluirChecklist->executar($checklist);

        return $this->json(['sucesso' => true]);
    }

    #[Route('/checklist/{checklistId}/item', name: 'kanban_checklist_item_criar', methods: ['POST'], requirements: ['checklistId' => '\d+'])]
    public function adicionarItem(int $checklistId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $this->assertAccess($user);

        $checklist = $this->checklistRepository->find($checklistId);
        if ($checklist === null || $checklist->getCard()->getBoard()->getTenant() !== $user->getTenant()) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Checklist não encontrada.'], 404);
        }

        $data   = $request->toArray();
        $input  = new AdicionarItemChecklistInput(texto: trim((string) ($data['texto'] ?? '')));
        $output = $this->adicionarItem->executar($input, $checklist);

        return $this->json(['sucesso' => true, 'item' => ['id' => $output->id, 'texto' => $output->texto, 'concluido' => $output->concluido]]);
    }

    #[Route('/checklist/item/{id}/toggle', name: 'kanban_checklist_item_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleItem(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        $item = $this->itemRepository->find($id);
        if ($item === null || $item->getChecklist()->getCard()->getBoard()->getTenant() !== $user->getTenant()) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Item não encontrado.'], 404);
        }

        $output = $this->marcarItem->executar($item);

        return $this->json(['sucesso' => true, 'concluido' => $output->concluido]);
    }

    #[Route('/checklist/item/{id}/excluir', name: 'kanban_checklist_item_excluir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function excluirItem(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('kanban_item_excluir_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Token inválido.'], 403);
        }

        $item = $this->itemRepository->find($id);
        if ($item === null || $item->getChecklist()->getCard()->getBoard()->getTenant() !== $user->getTenant()) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Item não encontrado.'], 404);
        }

        $this->removerItem->executar($item);

        return $this->json(['sucesso' => true]);
    }

    private function assertAccess(User $user): void
    {
        if (!$this->permissionChecker->canAccessModule($user, 'kanban')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Kanban.');
        }
    }
}
