<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/** O que a confirmação fez: quantas metas passaram e quantas mudaram antes da confirmação (e foram puladas). */
final readonly class ResultadoDaRedistribuicaoOutput
{
    public function __construct(
        public int $passadas,
        public int $puladas,
    ) {
    }

    /** @return array{passadas: int, puladas: int, total: int} */
    public function paraArray(): array
    {
        return ['passadas' => $this->passadas, 'puladas' => $this->puladas, 'total' => $this->passadas + $this->puladas];
    }
}
