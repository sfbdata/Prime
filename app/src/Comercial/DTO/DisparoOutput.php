<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/** Uma linha de "Últimos disparos" (dc L.267, L.565): gatilho, assunto, destinatário mascarado e quando. */
final class DisparoOutput
{
    public function __construct(
        public readonly string $gatilho,
        public readonly string $assunto,
        public readonly string $destinatario,
        public readonly string $em,
        public readonly bool $simulado,
    ) {
    }
}
