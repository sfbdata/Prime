<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

/** O Master escolhe quem recebe as mensagens do "Falar com suporte" (desenho 10, SÓ MASTER). */
final class DefinirDestinatarioSuporteInput
{
    public function __construct(
        public readonly int $userId,
        public readonly User $alteradoPor,
    ) {
    }
}
