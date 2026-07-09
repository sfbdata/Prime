<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do rompimento MANUAL de um Acordo (SPEC §12.9). O acordo é resolvido por id + tenant
 * no RomperAcordoUseCase (guarda multi-tenant) e só rompe se estiver `ativo`. O motivo é
 * obrigatório e registrado no acordo e no histórico; a normalização (trim) ocorre no UseCase.
 */
final class RomperAcordoInput
{
    #[Assert\NotNull(message: 'Informe o acordo.')]
    #[Assert\Positive(message: 'Acordo inválido.')]
    public ?int $acordoId = null;

    #[Assert\NotBlank(message: 'Informe o motivo do rompimento.', normalizer: 'trim')]
    public ?string $motivo = null;
}
