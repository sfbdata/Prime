<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do cancelamento MANUAL de um Acordo (SPEC §12). O acordo é resolvido por id + tenant
 * no CancelarAcordoUseCase (guarda multi-tenant) e só cancela se estiver `ativo`. O motivo é
 * OPCIONAL (diferente do rompimento); a normalização (trim; null se vazio) ocorre no UseCase.
 */
final class CancelarAcordoInput
{
    #[Assert\NotNull(message: 'Informe o acordo.')]
    #[Assert\Positive(message: 'Acordo inválido.')]
    public ?int $acordoId = null;

    #[Assert\Length(max: 255, maxMessage: 'O motivo pode ter no máximo {{ limit }} caracteres.')]
    public ?string $motivo = null;
}
