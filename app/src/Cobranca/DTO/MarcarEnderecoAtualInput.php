<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para marcar um endereço já existente como o `atual` da Pessoa (spec de qualificação §4).
 * A resolução de pessoa/endereço por id (guarda multi-tenant) e a troca de flag em transação única
 * ocorrem no MarcarEnderecoAtualUseCase.
 */
final class MarcarEnderecoAtualInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotNull(message: 'Informe o endereço.')]
    #[Assert\Positive(message: 'Endereço inválido.')]
    public ?int $enderecoId = null;
}
