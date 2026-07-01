<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class CriarEscritorioInput
{
    #[Assert\NotBlank(message: 'O nome do escritório é obrigatório.')]
    #[Assert\Length(max: 255)]
    public string $nome = '';

    public ?string $oabNumero = null;

    public ?string $oabUf = null;
}
