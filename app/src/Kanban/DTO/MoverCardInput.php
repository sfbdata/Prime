<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class MoverCardInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Positive]
        public readonly int $novaColunaId,

        #[Assert\PositiveOrZero]
        public readonly int $novaPosicao = 0,
    ) {
    }
}
