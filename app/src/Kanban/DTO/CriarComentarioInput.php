<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class CriarComentarioInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Conteúdo do comentário é obrigatório.')]
        public readonly string $conteudo,
    ) {
    }
}
