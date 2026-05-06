<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class CriarChecklistInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Título da checklist é obrigatório.')]
        #[Assert\Length(max: 255)]
        public readonly string $titulo,
    ) {
    }
}
