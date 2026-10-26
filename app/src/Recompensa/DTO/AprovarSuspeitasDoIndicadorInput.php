<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Entity\Auth\User;

/** "Revisar suspeitas e aprovar": o Master aprova todas as contas suspeitas pendentes de um indicador. */
final readonly class AprovarSuspeitasDoIndicadorInput
{
    public function __construct(
        /** Quem aprova: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public int $userId,
    ) {
    }
}
