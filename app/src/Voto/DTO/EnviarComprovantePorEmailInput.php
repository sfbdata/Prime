<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class EnviarComprovantePorEmailInput
{
    public function __construct(
        public string $token,
        public int $pauta,
        #[Assert\NotBlank(message: 'Informe um e-mail válido.')]
        #[Assert\Email(message: 'Informe um e-mail válido.')]
        #[Assert\Length(max: 180, maxMessage: 'Informe um e-mail válido.')]
        public string $email,
    ) {
    }
}
