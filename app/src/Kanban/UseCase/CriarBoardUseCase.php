<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\DTO\CriarBoardInput;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Repository\KanbanBoardRepository;
use App\Kanban\Repository\KanbanColunaRepository;
use App\Tenant\Service\ColaboradoresAtribuiveis;
use Doctrine\ORM\EntityManagerInterface;

final class CriarBoardUseCase
{
    public function __construct(
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanColunaRepository $colunaRepository,
        private readonly ColaboradoresAtribuiveis $atribuiveis,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(CriarBoardInput $input, User $criador, Tenant $tenant): int
    {
        $board = new KanbanBoard($input->nome, $tenant, $criador);
        $board->setDescricao($input->descricao);
        $board->setCor($input->cor);

        foreach ($this->atribuiveis->resolverVarios($input->participantesIds, $tenant) as $user) {
            if ($user !== $criador) {
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
