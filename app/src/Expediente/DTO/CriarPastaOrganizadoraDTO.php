<?php

namespace App\Expediente\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CriarPastaOrganizadoraDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'O nome da pasta é obrigatório.')]
        #[Assert\Length(max: 100, maxMessage: 'O nome pode ter no máximo {{ limit }} caracteres.')]
        public readonly string $nome,

        public readonly ?int $paiId = null,
    ) {}
}
