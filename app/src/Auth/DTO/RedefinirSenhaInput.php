<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class RedefinirSenhaInput
{
    #[Assert\NotBlank(message: 'Crie uma senha.')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'A senha deve ter ao menos {{ limit }} caracteres.')]
    public string $senha = '';
}
