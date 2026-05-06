<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class AdicionarItemChecklistInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Texto do item é obrigatório.')]
        #[Assert\Length(max: 500)]
        public readonly string $texto,
    ) {
    }
}
