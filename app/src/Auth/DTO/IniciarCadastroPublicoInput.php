<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class IniciarCadastroPublicoInput
{
    #[Assert\NotBlank(message: 'Informe seu nome completo.')]
    #[Assert\Length(max: 255)]
    public string $nomeCompleto = '';

    #[Assert\NotBlank(message: 'Informe seu e-mail.')]
    #[Assert\Email(message: 'E-mail inválido.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Crie uma senha.')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'A senha deve ter ao menos {{ limit }} caracteres.')]
    public string $senha = '';

    #[Assert\NotBlank(message: 'Informe o nome do escritório.')]
    #[Assert\Length(max: 255)]
    public string $nomeEscritorio = '';

    #[Assert\NotBlank(message: 'Informe o número da OAB.')]
    #[Assert\Length(max: 20)]
    public string $oabNumero = '';

    #[Assert\NotBlank(message: 'Informe a UF da OAB.')]
    #[Assert\Length(max: 2)]
    public string $oabUf = '';

    #[Assert\IsTrue(message: 'É necessário aceitar os Termos de Uso para criar a conta.')]
    public bool $aceiteTermos = false;
}
