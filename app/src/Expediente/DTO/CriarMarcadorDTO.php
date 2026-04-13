<?php

namespace App\Expediente\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CriarMarcadorDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'O nome do marcador é obrigatório.')]
        #[Assert\Length(max: 100, maxMessage: 'O nome pode ter no máximo {{ limit }} caracteres.')]
        public readonly string $nome,

        public readonly ?int $paiId = null,

        public readonly ?string $cor = null,
    ) {}
}
