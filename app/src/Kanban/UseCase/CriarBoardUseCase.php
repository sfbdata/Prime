<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\DTO\CriarBoardInput;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Repository\KanbanBoardRepository;
use App\Kanban\Repository\KanbanColunaRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CriarBoardUseCase
{
    public function __construct(
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanColunaRepository $colunaRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(CriarBoardInput $input, User $criador): int
    {
        $tenant = $criador->getTenant();

        $board = new KanbanBoard($input->nome, $tenant, $criador);
        $board->setDescricao($input->descricao);
        $board->setCor($input->cor);

        foreach ($input->participantesIds as $userId) {
            $user = $this->userRepository->findOneBy(['id' => $userId, 'tenant' => $tenant]);
            if ($user !== null && $user !== $criador) {
                $board->adicionarParticipante($user);
            }
        }

        $this->boardRepository->salvar($board);

        foreach (KanbanColuna::COLUNAS_PADRAO as $dados) {
            $coluna = new KanbanColuna($dados['nome'], $dados['tipo'], $dados['posicao'], $board);
            $this->colunaRepository->salvar($coluna);
        }

        $this->em->flush();

        return $board->getId();
    }
}
