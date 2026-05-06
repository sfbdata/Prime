<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Entity\Auth\User;
use App\Kanban\Entity\KanbanCard;

final class CardDetalheOutput
{
    /**
     * @param MarcadorOutput[]   $marcadores
     * @param UsuarioOutput[]    $responsaveis
     * @param ChecklistOutput[]  $checklists
     * @param AnexoOutput[]      $anexos
     * @param ComentarioOutput[] $comentarios
     * @param UsuarioOutput[]    $participantesDisponiveis
     * @param MarcadorOutput[]   $marcadoresDisponiveis
     */
    public function __construct(
        public readonly int $id,
        public readonly string $titulo,
        public readonly ?string $descricao,
        public readonly int $colunaId,
        public readonly string $colunaNome,
        public readonly int $boardId,
        public readonly string $boardNome,
        public readonly ?\DateTimeImmutable $dataVencimento,
        public readonly bool $estaVencido,
        public readonly array $marcadores,
        public readonly array $responsaveis,
        public readonly array $checklists,
        public readonly array $anexos,
        public readonly array $comentarios,
        public readonly array $participantesDisponiveis,
        public readonly array $marcadoresDisponiveis,
        public readonly \DateTimeImmutable $criadoEm,
    ) {
    }

    /**
     * @param MarcadorOutput[] $marcadoresDisponiveis
     */
    public static function fromEntity(KanbanCard $card, User $usuarioAtual, array $marcadoresDisponiveis = []): self
    {
        $coluna = $card->getColuna();
        $board  = $card->getBoard();

        $participantes = [];
        foreach ($board->getParticipantes() as $p) {
            $participantes[] = UsuarioOutput::fromEntity($p);
        }
        if (!$board->getParticipantes()->contains($board->getCriadoPor())) {
            array_unshift($participantes, UsuarioOutput::fromEntity($board->getCriadoPor()));
        }

        return new self(
            id: $card->getId(),
            titulo: $card->getTitulo(),
            descricao: $card->getDescricao(),
            colunaId: $coluna?->getId() ?? 0,
            colunaNome: $coluna?->getNome() ?? '',
            boardId: $board?->getId() ?? 0,
            boardNome: $board?->getNome() ?? '',
            dataVencimento: $card->getDataVencimento(),
            estaVencido: $card->estaVencido(),
            marcadores: array_map(fn($m) => MarcadorOutput::fromEntity($m), $card->getMarcadores()->toArray()),
            responsaveis: array_map(fn($u) => UsuarioOutput::fromEntity($u), $card->getResponsaveis()->toArray()),
            checklists: array_map(fn($cl) => ChecklistOutput::fromEntity($cl), $card->getChecklists()->toArray()),
            anexos: array_map(fn($a) => AnexoOutput::fromEntity($a), $card->getAnexos()->toArray()),
            comentarios: array_map(fn($c) => ComentarioOutput::fromEntity($c, $usuarioAtual), $card->getComentarios()->toArray()),
            participantesDisponiveis: $participantes,
            marcadoresDisponiveis: $marcadoresDisponiveis,
            criadoEm: $card->getCriadoEm() ?? new \DateTimeImmutable(),
        );
    }
}
