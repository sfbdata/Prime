<?php

declare(strict_types=1);

namespace App\Kanban\Controller;

use App\Entity\Auth\User;
use App\Kanban\DTO\AtualizarCardInput;
use App\Kanban\DTO\CardDetalheOutput;
use App\Kanban\DTO\CardSummaryOutput;
use App\Kanban\DTO\CriarCardInput;
use App\Kanban\Repository\KanbanBoardRepository;
use App\Kanban\Repository\KanbanCardRepository;
use App\Kanban\Repository\KanbanColunaRepository;
use App\Kanban\Repository\KanbanMarcadorRepository;
use App\Kanban\UseCase\AtualizarCardUseCase;
use App\Kanban\UseCase\CriarCardUseCase;
use App\Kanban\UseCase\ExcluirCardUseCase;
use App\Kanban\UseCase\MoverCardUseCase;
use App\Service\PermissionChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/kanban')]
final class KanbanCardController extends AbstractController
{
    public function __construct(
        private readonly CriarCardUseCase $criarCard,
        private readonly AtualizarCardUseCase $atualizarCard,
        private readonly ExcluirCardUseCase $excluirCard,
        private readonly MoverCardUseCase $moverCard,
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanColunaRepository $colunaRepository,
        private readonly KanbanCardRepository $cardRepository,
        private readonly KanbanMarcadorRepository $marcadorRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('/coluna/{colunaId}/card', name: 'kanban_card_criar', methods: ['POST'], requirements: ['colunaId' => '\d+'])]
    public function criar(int $colunaId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        $tenant = $user->getTenant();

        $data   = $request->toArray();
        $titulo = trim((string) ($data['titulo'] ?? ''));

        if ($titulo === '') {
            return $this->json(['sucesso' => false, 'mensagem' => 'Título é obrigatório.'], 422);
        }

        $input        = new CriarCardInput();
        $input->titulo = $titulo;

        $coluna = $this->colunaRepository->find($colunaId);
        if ($coluna === null || $coluna->getBoard()?->getTenant() !== $tenant) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Coluna inválida.'], 404);
        }

        if (!$coluna->getBoard()->temAcesso($user)) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Sem acesso ao mural.'], 403);
        }

        $cardId = $this->criarCard->executar($input, $coluna, $user);

        return $this->json(['sucesso' => true, 'cardId' => $cardId]);
    }

    #[Route('/card/{id}/mini', name: 'kanban_card_mini', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function mini(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        $card = $this->cardRepository->findPorTenantEId($id, $user->getTenant());
        if ($card === null || !$card->getBoard()->temAcesso($user)) {
            return $this->json(['sucesso' => false], 404);
        }

        $output = CardSummaryOutput::fromEntity($card);
        $html   = $this->renderView('kanban/_partials/_card_mini.html.twig', ['card' => $output]);

        return $this->json(['sucesso' => true, 'html' => $html]);
    }

    #[Route('/card/{id}', name: 'kanban_card_detalhes', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detalhes(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        $card = $this->cardRepository->findPorTenantEId($id, $user->getTenant());
        if ($card === null || !$card->getBoard()->temAcesso($user)) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Card não encontrado.'], 404);
        }

        $marcadoresDisponiveis = array_map(
            fn($m) => \App\Kanban\DTO\MarcadorOutput::fromEntity($m),
            $this->marcadorRepository->findPorBoard($card->getBoard())
        );
        $output = CardDetalheOutput::fromEntity($card, $user, $marcadoresDisponiveis);
        $html   = $this->renderView('kanban/_partials/_card_modal_body.html.twig', ['card' => $output]);

        return $this->json(['sucesso' => true, 'html' => $html, 'card' => [
            'id'     => $output->id,
            'titulo' => $output->titulo,
        ]]);
    }

    #[Route('/card/{id}', name: 'kanban_card_atualizar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function atualizar(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        $card = $this->cardRepository->findPorTenantEId($id, $user->getTenant());
        if ($card === null || !$card->getBoard()->temAcesso($user)) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Card não encontrado.'], 404);
        }

        $data  = $request->toArray();
        $input = new AtualizarCardInput(
            titulo: trim((string) ($data['titulo'] ?? '')),
            descricao: ($data['descricao'] ?? null) ?: null,
            dataVencimento: ($data['dataVencimento'] ?? null) ?: null,
            responsaveisIds: array_map('intval', (array) ($data['responsaveisIds'] ?? [])),
        );

        if ($input->titulo === '') {
            return $this->json(['sucesso' => false, 'mensagem' => 'Título é obrigatório.'], 422);
        }

        $output = $this->atualizarCard->executar($card, $input, $user);

        return $this->json(['sucesso' => true, 'titulo' => $output->titulo]);
    }

    #[Route('/card/{id}/mover', name: 'kanban_card_mover', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function mover(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        $card = $this->cardRepository->findPorTenantEId($id, $user->getTenant());
        if ($card === null) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Card não encontrado.'], 404);
        }

        $data = $request->toArray();

        $input = new \App\Kanban\DTO\MoverCardInput(
            novaColunaId: (int) ($data['novaColunaId'] ?? 0),
            novaPosicao: (int) ($data['novaPosicao'] ?? 0),
        );

        $this->moverCard->executar($card, $input, $user);

        return $this->json(['sucesso' => true]);
    }

    #[Route('/card/{id}/excluir', name: 'kanban_card_excluir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function excluir(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('kanban_card_excluir_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Token inválido.'], 403);
        }

        $card = $this->cardRepository->findPorTenantEId($id, $user->getTenant());
        if ($card === null || !$card->getBoard()->temAcesso($user)) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Card não encontrado.'], 404);
        }

        $colunaId = $card->getColuna()?->getId();
        $this->excluirCard->executar($card);

        return $this->json(['sucesso' => true, 'colunaId' => $colunaId]);
    }

    private function assertAccess(User $user): void
    {
        if (!$this->permissionChecker->canAccessModule($user, 'kanban')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Kanban.');
        }
    }

}
