<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

final readonly class RemoverMasterInput
{
    public function __construct(
        /** Quem remove: só o Master primário (M3). */
        public User $ator,
        /** Id do Master secundário a remover (rota /master/masters/{userId}/remover). */
        public int $alvoId,
    ) {
    }
}
