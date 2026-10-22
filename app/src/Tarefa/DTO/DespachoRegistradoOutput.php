<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/** Resultado do Despachar: o código do registro (nulo quando só mudou o status) e a transição feita. */
final class DespachoRegistradoOutput
{
    public function __construct(
        public readonly ?string $codigo,
        public readonly bool $enviada,
        public readonly bool $devolvida,
    ) {
    }
}
