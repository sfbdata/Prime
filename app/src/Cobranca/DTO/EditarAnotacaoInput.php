<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da correção de uma ANOTAÇÃO da linha do tempo (ajuste 2026-07-22). O evento alvo vem da
 * rota; quem pode corrigir (autor, dentro de 48h, só anotação) é decidido no UseCase a partir da
 * entidade — nunca aqui, que só valida formato.
 */
final class EditarAnotacaoInput
{
    public ?int $eventoId = null;

    #[Assert\NotBlank(message: 'A anotação não pode ficar vazia. Para removê-la, use o botão de excluir.')]
    #[Assert\Length(
        max: 5000,
        maxMessage: 'A anotação pode ter no máximo {{ limit }} caracteres.',
    )]
    public ?string $texto = null;
}
