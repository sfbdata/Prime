<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\DTO\AtualizarCardInput;
use App\Kanban\DTO\CardDetalheOutput;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Repository\KanbanCardRepository;
use App\Kanban\Service\SanitizadorTextoKanban;
use App\Repository\UserRepository;

final class AtualizarCardUseCase
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepository,
        private readonly UserRepository $userRepository,
        private readonly SanitizadorTextoKanban $sanitizador,
    ) {
    }

    public function executar(KanbanCard $card, AtualizarCardInput $input, User $usuarioAtual, Tenant $tenant): CardDetalheOutput
    {

        $card->setTitulo($input->titulo);
        // A descrição vem do editor Quill (HTML): sanitizada ANTES de persistir, para o banco nunca
        // guardar marcação perigosa — era exatamente a origem do XSS armazenado do Kanban.
        $card->setDescricao($this->sanitizador->limpar($input->descricao));

        if ($input->dataVencimento !== null && $input->dataVencimento !== '') {
            $card->setDataVencimento(new \DateTimeImmutable($input->dataVencimento));
        } else {
            $card->setDataVencimento(null);
        }

        foreach ($card->getResponsaveis()->toArray() as $responsavel) {
            $card->removerResponsavel($responsavel);
        }

        foreach ($input->responsaveisIds as $userId) {
            $user = $this->userRepository->findPorIdETenant($userId, $tenant);
            if ($user !== null) {
                $card->adicionarResponsavel($user);
            }
        }

        $this->cardRepository->salvar($card, flush: true);

        return CardDetalheOutput::fromEntity($card, $usuarioAtual);
    }
}
