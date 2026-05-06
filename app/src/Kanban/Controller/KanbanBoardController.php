<?php

declare(strict_types=1);

namespace App\Kanban\Controller;

use App\Entity\Auth\User;
use App\Kanban\DTO\AtualizarBoardInput;
use App\Kanban\DTO\BoardDetalheOutput;
use App\Kanban\DTO\CriarBoardInput;
use App\Kanban\Form\KanbanBoardEditType;
use App\Kanban\Form\KanbanBoardType;
use App\Kanban\Repository\KanbanBoardRepository;
use App\Kanban\Repository\KanbanMarcadorRepository;
use App\Kanban\UseCase\AtualizarBoardUseCase;
use App\Kanban\UseCase\CriarBoardUseCase;
use App\Kanban\UseCase\ExcluirBoardUseCase;
use App\Kanban\UseCase\ListarBoardsUseCase;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/kanban')]
final class KanbanBoardController extends AbstractController
{
    public function __construct(
        private readonly ListarBoardsUseCase $listarBoards,
        private readonly CriarBoardUseCase $criarBoard,
        private readonly AtualizarBoardUseCase $atualizarBoard,
        private readonly ExcluirBoardUseCase $excluirBoard,
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanMarcadorRepository $marcadorRepository,
        private readonly UserRepository $userRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('', name: 'kanban_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        $boards    = $this->listarBoards->executar($user);
        $form      = $this->createForm(KanbanBoardType::class, new CriarBoardInput());
        $usuarios  = $this->userRepository->findBy(['tenant' => $user->getTenant()]);

        return $this->render('kanban/index.html.twig', [
            'boards'   => $boards,
            'form'     => $form,
            'usuarios' => $usuarios,
        ]);
    }

    #[Route('', name: 'kanban_board_criar', methods: ['POST'])]
    public function criar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        $input = new CriarBoardInput();
        $form  = $this->createForm(KanbanBoardType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->json(['sucesso' => false, 'erros' => $this->getFormErrors($form)], 422);
        }

        $input->participantesIds = array_map('intval', (array) $request->request->all('participantesIds'));

        $id = $this->criarBoard->executar($input, $user);

        return $this->json([
            'sucesso'     => true,
            'redirectUrl' => $this->generateUrl('kanban_board_view', ['id' => $id]),
        ]);
    }

    #[Route('/{id}', name: 'kanban_board_view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(int $id): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $this->assertAccess($user);

        $board = $this->boardRepository->findPorTenantEId($id, $user->getTenant());
        if ($board === null || !$board->temAcesso($user)) {
            throw $this->createNotFoundException('Mural não encontrado.');
        }

        $output       = BoardDetalheOutput::fromEntity($board, $user);
        $marcadores   = $this->marcadorRepository->findPorBoard($board);
        $editInput    = new AtualizarBoardInput();
        $editInput->nome      = $board->getNome();
        $editInput->descricao = $board->getDescricao();
        $editInput->cor       = $board->getCor();
        $formEdit     = $this->createForm(KanbanBoardEditType::class, $editInput);
        $usuarios     = $this->userRepository->findBy(['tenant' => $user->getTenant()]);

        return $this->render('kanban/board.html.twig', [
            'board'      => $output,
            'marcadores' => $marcadores,
            'formEdit'   => $formEdit,
            'usuarios'   => $usuarios,
        ]);
    }

    #[Route('/{id}', name: 'kanban_board_editar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $this->assertAccess($user);

        $board = $this->boardRepository->findPorTenantEId($id, $user->getTenant());
        if ($board === null || !$board->temAcesso($user)) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Mural não encontrado.'], 404);
        }

        $input = new AtualizarBoardInput();
        $form  = $this->createForm(KanbanBoardEditType::class, $input);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->json(['sucesso' => false, 'erros' => $this->getFormErrors($form)], 422);
        }

        $input->participantesIds = array_map('intval', (array) $request->request->all('participantesIds'));

        $this->atualizarBoard->executar($board, $input, $user);

        return $this->json(['sucesso' => true]);
    }

    #[Route('/{id}/excluir', name: 'kanban_board_excluir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function excluir(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertAccess($user);

        if (!$this->isCsrfTokenValid('kanban_board_excluir_' . $id, (string) $request->request->get('_token'))) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Token inválido.'], 403);
        }

        $board = $this->boardRepository->findPorTenantEId($id, $user->getTenant());
        if ($board === null) {
            return $this->json(['sucesso' => false, 'mensagem' => 'Mural não encontrado.'], 404);
        }

        $this->excluirBoard->executar($board, $user);

        return $this->json(['sucesso' => true, 'redirectUrl' => $this->generateUrl('kanban_index')]);
    }

    private function assertAccess(User $user): void
    {
        if (!$this->permissionChecker->canAccessModule($user, 'kanban')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Kanban.');
        }
    }

    private function getFormErrors(\Symfony\Component\Form\FormInterface $form): array
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        return $errors;
    }
}
