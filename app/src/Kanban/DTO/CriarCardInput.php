<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class CriarCardInput
{
    #[Assert\NotBlank(message: 'Título do card é obrigatório.')]
    #[Assert\Length(max: 500)]
    public ?string $titulo = null;
}
