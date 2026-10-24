<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/** Bloco DESPACHO e despachos da linha do tempo da meta, por id da mensagem. */
final class DespachosDaMetaOutput
{
    /** @param array<int, DespachoNaLinhaDoTempoOutput> $linhas */
    public function __construct(
        public readonly FormularioDoDespachoOutput $formulario,
        public readonly array $linhas,
    ) {
    }
}
