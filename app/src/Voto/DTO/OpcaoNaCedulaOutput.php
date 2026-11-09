<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Uma opção da pauta aberta na cédula do morador. Nunca leva contador: a cédula só mostra totais na pauta encerrada. */
final readonly class OpcaoNaCedulaOutput
{
    public function __construct(
        public int $id,
        public int $numero,
        public string $rotulo,
        public bool $abstencao,
    ) {
    }
}
