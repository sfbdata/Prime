<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\DTO\AtualizarBoardInput;
use App\Kanban\Entity\KanbanBoard;
use App\Tenant\Service\ColaboradoresAtribuiveis;

final class AtualizarBoardUseCase
{
    public function __construct(
        private readonly ColaboradoresAtribuiveis $atribuiveis,
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

        $atuais = $board->getParticipantes()->toArray();
        foreach ($atuais as $participante) {
            $board->removerParticipante($participante);
        }

        foreach ($this->atribuiveis->resolverVarios($input->participantesIds, $tenant, $atuais) as $user) {
            if ($user !== $board->getCriadoPor()) {
                $board->adicionarParticipante($user);
            }
        }
    }
}
