<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class AtualizarCardInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Título é obrigatório.')]
        #[Assert\Length(max: 500)]
        public readonly string $titulo,

        public readonly ?string $descricao = null,

        public readonly ?string $dataVencimento = null,

        /** @var int[] */
        public readonly array $responsaveisIds = [],
    ) {
    }
}
