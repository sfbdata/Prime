<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/** Suspender ou reativar uma conta pelo painel do Master (spec perfis-1.2-contas C2 e C3). */
final readonly class SituacaoDaContaInput
{
    public function __construct(
        /** Quem confirma no painel: um Master (primário ou secundário). */
        public User $ator,
        /** Id do escritório (rota /master/contas/{id}/...). */
        public int $contaId,
    ) {
    }
}
