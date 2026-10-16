<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** O Painel "Equipe · em aberto" e a pílula "N on-line" do cabeçalho. */
final class EquipeOutput
{
    /** @param list<MembroDaEquipeOutput> $equipe */
    public function __construct(
        public readonly array $equipe,
        public readonly int $online,
        public readonly int $total,
    ) {
    }
}
