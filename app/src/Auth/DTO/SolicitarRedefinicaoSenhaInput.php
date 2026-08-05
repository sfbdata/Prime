<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class SolicitarRedefinicaoSenhaInput
{
    #[Assert\NotBlank(message: 'Informe seu e-mail.')]
    #[Assert\Email(message: 'E-mail inválido.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    /**
     * Campo-armadilha (honeypot): fica oculto no formulário e só um robô preenche.
     * Preenchido = descarta em silêncio, com a mesma resposta neutra de sempre.
     */
    public string $confirmacao = '';
}
