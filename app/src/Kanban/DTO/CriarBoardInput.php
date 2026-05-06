<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class CriarBoardInput
{
    #[Assert\NotBlank(message: 'Nome do mural é obrigatório.')]
    #[Assert\Length(max: 255)]
    public ?string $nome = null;

    #[Assert\Length(max: 2000)]
    public ?string $descricao = null;

    #[Assert\Length(max: 7)]
    public ?string $cor = null;

    /** @var int[] */
    public array $participantesIds = [];
}
