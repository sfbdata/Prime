<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SolicitarCodigoDaAssembleiaInput
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Length(max: 160)] public string $nome,
        #[Assert\NotBlank] #[Assert\Length(max: 80)] public string $unidade,
        #[Assert\Length(max: 40)] public string $bloco,
        #[Assert\Email] #[Assert\NotBlank] #[Assert\Length(max: 180)] public string $email,
        #[Assert\Regex('/^[a-f0-9]{32}$/')] public string $desafio,
    ) {
    }
}
