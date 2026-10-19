<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/** O que o lembrete precisa para mandar o link novo. O token só vive no e-mail (não guardar). */
final class ConfirmacaoReemitidaOutput
{
    public function __construct(
        public readonly string $email,
        public readonly string $nomeCompleto,
        public readonly string $token,
    ) {
    }
}
