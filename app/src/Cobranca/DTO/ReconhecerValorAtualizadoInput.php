<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do reconhecimento manual de valor atualizado de uma Obrigação (SPEC §10). O gestor
 * informa os encargos (juros/multa/correção) reconhecidos, em CENTAVOS, sem recálculo automático
 * e sem criar obrigação nova — o valor ORIGINAL é sempre preservado (invariável 20). A resolução
 * da obrigação por id (guarda multi-tenant) e a persistência ocorrem no
 * ReconhecerValorAtualizadoUseCase; aqui só se validam formato e presença dos campos.
 */
final class ReconhecerValorAtualizadoInput
{
    #[Assert\NotNull(message: 'Informe a obrigação.')]
    #[Assert\Positive(message: 'Obrigação inválida.')]
    public ?int $obrigacaoId = null;

    /** Encargos reconhecidos em CENTAVOS; zero é permitido (zera um reconhecimento anterior). */
    #[Assert\PositiveOrZero(message: 'Os encargos reconhecidos não podem ser negativos.')]
    public int $encargosReconhecidos = 0;

    #[Assert\Length(max: 255, maxMessage: 'O motivo pode ter no máximo {{ limit }} caracteres.')]
    public ?string $motivo = null;
}
