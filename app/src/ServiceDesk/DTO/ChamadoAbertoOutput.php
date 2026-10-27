<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

final class ChamadoAbertoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly int $numero,
    ) {
    }

    public function numeroFormatado(): string
    {
        return '#' . str_pad((string) $this->numero, 4, '0', STR_PAD_LEFT);
    }
}
