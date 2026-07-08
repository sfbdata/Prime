<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do cadastro de Pessoa (SPEC §7/§24). É a data_class do formulário de cobranças.
 * CPF e CNPJ são OPCIONAIS por regra de negócio: a ausência não impede o cadastro. A
 * normalização final (trim; null se vazio) ocorre no CriarPessoaUseCase.
 */
final class CriarPessoaInput
{
    #[Assert\NotBlank(message: 'Informe o nome da pessoa.')]
    #[Assert\Length(max: 255, maxMessage: 'O nome pode ter no máximo {{ limit }} caracteres.')]
    public ?string $nome = null;

    #[Assert\Length(max: 14, maxMessage: 'O CPF pode ter no máximo {{ limit }} caracteres.')]
    public ?string $cpf = null;

    #[Assert\Length(max: 18, maxMessage: 'O CNPJ pode ter no máximo {{ limit }} caracteres.')]
    public ?string $cnpj = null;

    #[Assert\Email(message: 'Informe um e-mail válido.')]
    public ?string $email = null;

    #[Assert\Length(max: 20, maxMessage: 'O telefone pode ter no máximo {{ limit }} caracteres.')]
    public ?string $telefone = null;

    public ?string $observacao = null;
}
