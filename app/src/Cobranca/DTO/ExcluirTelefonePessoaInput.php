<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para EXCLUIR um telefone da lista de uma Pessoa (spec de qualificação §7: "nunca apagar
 * histórico" vale para as ações rotineiras — marcar atual só troca a flag —, e excluir é a ação
 * explícita que a própria spec prevê à parte).
 *
 * A resolução por id (guarda multi-tenant), a remoção e a promoção do sucessor a `atual` ocorrem no
 * ExcluirTelefonePessoaUseCase.
 */
final class ExcluirTelefonePessoaInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotNull(message: 'Informe o telefone.')]
    #[Assert\Positive(message: 'Telefone inválido.')]
    public ?int $telefoneId = null;
}
