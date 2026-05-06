<?php

declare(strict_types=1);

namespace App\Kanban\Controller;

use App\Entity\Auth\User;
use App\Kanban\DTO\CriarMarcadorInput;
use App\Kanban\Repository\KanbanBoardRepository;
use App\Kanban\Repository\KanbanCardRepository;
use App\Kanban\Repository\KanbanMarcadorRepository;
use App\Kanban\UseCase\AtualizarMarcadorUseCase;
use App\Kanban\UseCase\CriarMarcadorUseCase;
use App\Kanban\UseCase\ExcluirMarcadorUseCase;
use App\Kanban\UseCase\ToggleMarcadorNoCardUseCase;
use App\Service\PermissionChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/kanban')]
final class KanbanMarcadorController extends AbstractController
{
    public function __construct(
        private readonly CriarMarcadorUseCase $criarMarcador,
        private readonly AtualizarMarcadorUseCase $atualizarMarcador,
        private readonly ExcluirMarcadorUseCase $excluirMarcador,
        private readonly ToggleMarcadorNoCardUseCase $toggleMarcador,
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanMarcadorRepository $marcadorRepository,
        private readonly KanbanCardRepository $cardRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('/board/{boardId}/marcador', name: 'kanban_marcador_criar', methods: ['POST'], requirements: ['boardId' => '\d+'])]
    public function criar(int $boardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $this->assertAccess($user);

        $board = $this->boardRepository->findPorTenantEId($boardId, $user->getTenant());
        if ($board === null || !$board->temAcesso($user)) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Mural não encontrado.'], 404);
        }

        $data  = $request->toArray();
        $input = new CriarMarcadorInput(
            nome: trim((string) ($data['nome'] ?? '')),
            cor: trim((string) ($data['cor'] ?? '#3b82f6')),
        );

        $output = $this->criarMarcador->executar($input, $board);

        return $this->json(['sucesso' => true, 'marcador' => ['id' => $output->id, 'nome' => $output->nome, 'cor' => $output->cor]]);
    }

    #[Route('/marcador/{id}', name: 'kanban_marcador_editar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $this->assertAccess($user);

        $marcador = $this->marcadorRepository->find($id);
        if ($marcador === null || $marcador->getBoard()->getTenant() !== $user->getTenant()) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Marcador não encontrado.'], 404);
        }

        $data  = $request->toArray();
        $input = new CriarMarcadorInput(
            nome: trim((string) ($data['nome'] ?? $marcador->getNome())),
            cor: trim((string) ($data['cor'] ?? $marcador->getCor())),
        );

        $output = $this->atualizarMarcador->executar($marcador, $input);

        return $this->json(['sucesso' => true, 'marcador' => ['id' => $output->id, 'nome' => $output->nome, 'cor' => $output->cor]]);
    }

    #[Route('/marcador/{id}/excluir', name: 'kanban_marcador_excluir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function excluir(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('kanban_marcador_excluir_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Token inválido.'], 403);
        }

        $marcador = $this->marcadorRepository->find($id);
        if ($marcador === null || $marcador->getBoard()->getTenant() !== $user->getTenant()) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Marcador não encontrado.'], 404);
        }

        $this->excluirMarcador->executar($marcador);

        return $this->json(['sucesso' => true]);
    }

    #[Route('/card/{cardId}/marcador/{marcadorId}/toggle', name: 'kanban_marcador_toggle', methods: ['POST'], requirements: ['cardId' => '\d+', 'marcadorId' => '\d+'])]
    public function toggle(int $cardId, int $marcadorId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $this->assertAccess($user);

        $card = $this->cardRepository->findPorTenantEId($cardId, $user->getTenant());
        if ($card === null || !$card->getBoard()->temAcesso($user)) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Card não encontrado.'], 404);
        }

        $board    = $card->getBoard();
        $marcador = $this->marcadorRepository->findPorBoardEId($marcadorId, $board);
        if ($marcador === null) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Marcador não encontrado.'], 404);
        }

        $adicionado = $this->toggleMarcador->executar($card, $marcador);

        return $this->json(['sucesso' => true, 'adicionado' => $adicionado]);
    }

    private function assertAccess(User $user): void
    {
        if (!$this->permissionChecker->canAccessModule($user, 'kanban')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Kanban.');
        }
    }
}
