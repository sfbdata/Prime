<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do encerramento de um Vínculo Pessoa↔Objeto (SPEC §7). Será a data_class do respectivo
 * Type. O motivo do encerramento é OBRIGATÓRIO por regra de negócio — todo encerramento decorre de
 * um evento real (venda, saída, fim de locação...). A resolução do vínculo por id (guarda
 * multi-tenant) e a persistência ocorrem no EncerrarVinculoUseCase. A data final, se ausente, é
 * assumida como hoje pelo UseCase.
 */
final class EncerrarVinculoInput
{
    #[Assert\NotNull(message: 'Informe o vínculo a encerrar.')]
    #[Assert\Positive(message: 'Vínculo inválido.')]
    public ?int $vinculoId = null;

    #[Assert\NotBlank(message: 'Informe o motivo do encerramento.')]
    #[Assert\Length(max: 255, maxMessage: 'O motivo pode ter no máximo {{ limit }} caracteres.')]
    public ?string $motivoEncerramento = null;

    public ?\DateTimeImmutable $dataFim = null;

    public ?string $observacao = null;
}
