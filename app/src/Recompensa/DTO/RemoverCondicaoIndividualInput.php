<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Entity\Auth\User;

/** "Voltar à regra geral": o Master tira a condição individual de um indicador. */
final readonly class RemoverCondicaoIndividualInput
{
    public function __construct(
        /** Quem altera: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public int $userId,
    ) {
    }
}
