<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class CriarMarcadorInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Nome do marcador é obrigatório.')]
        #[Assert\Length(max: 100)]
        public readonly string $nome,

        #[Assert\NotBlank(message: 'Cor é obrigatória.')]
        #[Assert\Length(min: 4, max: 7)]
        public readonly string $cor,
    ) {
    }
}
