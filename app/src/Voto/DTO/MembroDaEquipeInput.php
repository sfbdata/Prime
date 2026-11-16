<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Os campos da tela Perfis (desenho H247): Nome, E-mail, Cargo e Permissões (o valor de `PapelNaEquipe`). */
final readonly class MembroDaEquipeInput
{
    public function __construct(
        public string $nome,
        public string $email,
        public string $cargo,
        public string $papel,
    ) {
    }
}
