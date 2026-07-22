<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para adicionar um novo telefone à lista de uma Pessoa (spec de qualificação §4). Será a
 * data_class do respectivo Type (Onda de UI). A resolução da pessoa por id (guarda multi-tenant), a
 * regra de "primeiro item nasce atual" e a persistência ocorrem no AdicionarTelefonePessoaUseCase.
 */
final class AdicionarTelefonePessoaInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotBlank(message: 'Informe o telefone.')]
    #[Assert\Length(max: 20, maxMessage: 'O telefone pode ter no máximo {{ limit }} caracteres.')]
    public ?string $numero = null;
}
