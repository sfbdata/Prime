<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Dashboard\Entity\DashboardMetaPastas;

/** O que a gravação da meta devolve: o valor (0 = sem meta) e o mês de vigência ('Y-m'). */
final class MetaDePastasGravadaOutput
{
    public function __construct(
        public readonly int $valor,
        public readonly string $desde,
    ) {
    }

    public static function fromEntity(DashboardMetaPastas $meta): self
    {
        return new self($meta->getValor(), $meta->getMes()->format('Y-m'));
    }

    /** @return array{valor: int, desde: string} */
    public function paraArray(): array
    {
        return ['valor' => $this->valor, 'desde' => $this->desde];
    }
}
