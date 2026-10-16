<?php

declare(strict_types=1);

namespace App\Central\DTO;

use App\Entity\Auth\User;

/** O texto com as menções conferidas e quem, de fato, foi mencionado (sem repetição, na ordem do texto). */
final class MencoesResolvidas
{
    /** @param list<User> $mencionados */
    public function __construct(
        public readonly string $conteudo,
        public readonly array $mencionados,
    ) {
    }
}
