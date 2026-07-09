<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da marcação de um Acordo como cumprido (SPEC §12/§28): decisão MANUAL quando as
 * parcelas foram quitadas. O acordo é resolvido por id + tenant no MarcarAcordoCumpridoUseCase
 * (guarda multi-tenant) e só transiciona se estiver `ativo`.
 */
final class MarcarAcordoCumpridoInput
{
    #[Assert\NotNull(message: 'Informe o acordo.')]
    #[Assert\Positive(message: 'Acordo inválido.')]
    public ?int $acordoId = null;
}
