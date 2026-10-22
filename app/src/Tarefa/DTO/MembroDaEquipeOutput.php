<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/** Uma pessoa da equipe que pode ser marcada com @ no modal "Configurar caixa" (nome e função, dc L133). */
final class MembroDaEquipeOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly ?string $funcao = null,
    ) {
    }
}
