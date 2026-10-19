<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

final readonly class PromoverMasterInput
{
    public function __construct(
        /** Quem promove: só o Master primário (M3). */
        public User $ator,
        /** O escritório da sessão do ator: só um secundário DELE pode ser promovido (M5). */
        public ?Tenant $escritorio,
        /** Id do usuário escolhido no select; 0 quando nada foi escolhido. */
        public int $alvoId,
    ) {
    }
}
