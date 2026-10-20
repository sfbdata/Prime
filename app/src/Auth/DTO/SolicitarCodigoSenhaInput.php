<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Passo 1 da senha por código (modal "Recuperar senha" do login): só o e-mail.
 * A mensagem é a do desenho 10, a mesma para vazio, malformado ou comprido demais.
 */
final class SolicitarCodigoSenhaInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Informe um e-mail válido.')]
        #[Assert\Email(message: 'Informe um e-mail válido.')]
        #[Assert\Length(max: 180, maxMessage: 'Informe um e-mail válido.')]
        public readonly string $email,
    ) {
    }
}
