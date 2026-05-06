<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\DTO\AtualizarCardInput;
use App\Kanban\DTO\CardDetalheOutput;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Repository\KanbanCardRepository;
use App\Repository\UserRepository;

final class AtualizarCardUseCase
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function executar(KanbanCard $card, AtualizarCardInput $input, User $usuarioAtual): CardDetalheOutput
    {
        $tenant = $usuarioAtual->getTenant();

        $card->setTitulo($input->titulo);
        $card->setDescricao($input->descricao);

        if ($input->dataVencimento !== null && $input->dataVencimento !== '') {
            $card->setDataVencimento(new \DateTimeImmutable($input->dataVencimento));
        } else {
            $card->setDataVencimento(null);
        }

        foreach ($card->getResponsaveis()->toArray() as $responsavel) {
            $card->removerResponsavel($responsavel);
        }

        foreach ($input->responsaveisIds as $userId) {
            $user = $this->userRepository->findOneBy(['id' => $userId, 'tenant' => $tenant]);
            if ($user !== null) {
                $card->adicionarResponsavel($user);
            }
        }

        $this->cardRepository->salvar($card, flush: true);

        return CardDetalheOutput::fromEntity($card, $usuarioAtual);
    }
}
