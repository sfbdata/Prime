<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para adicionar um novo e-mail à lista de uma Pessoa (spec de qualificação §4). Será a
 * data_class do respectivo Type (Onda de UI). A resolução da pessoa por id (guarda multi-tenant), a
 * regra de "primeiro item nasce atual" e a persistência ocorrem no AdicionarEmailPessoaUseCase.
 */
final class AdicionarEmailPessoaInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotBlank(message: 'Informe o e-mail.')]
    #[Assert\Email(message: 'Informe um e-mail válido.')]
    #[Assert\Length(max: 255, maxMessage: 'O e-mail pode ter no máximo {{ limit }} caracteres.')]
    public ?string $email = null;
}
