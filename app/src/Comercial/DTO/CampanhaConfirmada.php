<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/** A resposta do "Enviar campanha" (dc L.560): o id e a mensagem de sucesso. */
final class CampanhaConfirmada
{
    public function __construct(
        public readonly int $id,
        public readonly int $total,
        public readonly string $mensagem,
    ) {
    }
}
