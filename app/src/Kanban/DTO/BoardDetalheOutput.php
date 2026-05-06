<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Entity\Auth\User;
use App\Kanban\Entity\KanbanBoard;

final class BoardDetalheOutput
{
    /**
     * @param ColunaOutput[]    $colunas
     * @param UsuarioOutput[]   $participantes
     * @param MarcadorOutput[]  $marcadores
     */
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly ?string $descricao,
        public readonly ?string $cor,
        public readonly array $colunas,
        public readonly array $participantes,
        public readonly array $marcadores,
        public readonly bool $podeAdministrar,
    ) {
    }

    public static function fromEntity(KanbanBoard $board, User $usuarioAtual): self
    {
        $colunas = array_map(
            fn($c) => ColunaOutput::fromEntity($c),
            $board->getColunas()->toArray()
        );

        $participantes = array_map(
            fn($u) => UsuarioOutput::fromEntity($u),
            $board->getParticipantes()->toArray()
        );

        $marcadores = [];
        foreach ($board->getColunas() as $coluna) {
            foreach ($coluna->getCards() as $card) {
                foreach ($card->getMarcadores() as $m) {
                    $marcadores[$m->getId()] = MarcadorOutput::fromEntity($m);
                }
            }
        }

        $podeAdministrar = $board->getCriadoPor() === $usuarioAtual;

        return new self(
            id: $board->getId(),
            nome: $board->getNome(),
            descricao: $board->getDescricao(),
            cor: $board->getCor(),
            colunas: $colunas,
            participantes: $participantes,
            marcadores: array_values($marcadores),
            podeAdministrar: $podeAdministrar,
        );
    }
}
