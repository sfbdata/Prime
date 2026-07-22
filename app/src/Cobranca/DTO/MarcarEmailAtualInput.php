<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para marcar um e-mail já existente como o `atual` da Pessoa (spec de qualificação §4).
 * A resolução de pessoa/e-mail por id (guarda multi-tenant) e a troca de flag em transação única
 * ocorrem no MarcarEmailAtualUseCase.
 */
final class MarcarEmailAtualInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotNull(message: 'Informe o e-mail.')]
    #[Assert\Positive(message: 'E-mail inválido.')]
    public ?int $emailId = null;
}
