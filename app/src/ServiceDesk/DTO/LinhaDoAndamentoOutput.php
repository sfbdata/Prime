<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

final class LinhaDoAndamentoOutput
{
    public function __construct(
        public readonly \DateTimeImmutable $em,
        public readonly string $texto,
    ) {
    }
}
