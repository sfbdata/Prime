<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\DTO\AtualizarBoardInput;
use App\Kanban\Entity\KanbanBoard;
use App\Repository\UserRepository;

final class AtualizarBoardUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function executar(KanbanBoard $board, AtualizarBoardInput $input, User $usuarioAtual, Tenant $tenant): void
    {
        if (!$board->temAcesso($usuarioAtual) && $board->getCriadoPor() !== $usuarioAtual) {
            throw new \RuntimeException('Apenas o criador pode editar o mural.');
        }

        $board->setNome($input->nome);
        $board->setDescricao($input->descricao);
        $board->setCor($input->cor);

        foreach ($board->getParticipantes()->toArray() as $participante) {
            $board->removerParticipante($participante);
        }

        foreach ($input->participantesIds as $userId) {
            $user = $this->userRepository->findOneBy(['id' => $userId, 'tenant' => $tenant]);
            if ($user !== null && $user !== $board->getCriadoPor()) {
                $board->adicionarParticipante($user);
            }
        }
    }
}
