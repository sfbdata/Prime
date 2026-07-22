<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para marcar um telefone já existente como o `atual` da Pessoa (spec de qualificação §4).
 * A resolução de pessoa/telefone por id (guarda multi-tenant) e a troca de flag em transação única
 * ocorrem no MarcarTelefoneAtualUseCase.
 */
final class MarcarTelefoneAtualInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotNull(message: 'Informe o telefone.')]
    #[Assert\Positive(message: 'Telefone inválido.')]
    public ?int $telefoneId = null;
}
