<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do encerramento de um vínculo temporal (SPEC §7). A resolução do vínculo por id + tenant
 * (guarda multi-tenant) e a persistência ocorrem no EncerrarVinculoUseCase — aqui só se validam
 * formato e presença dos campos. Encerrar EXIGE motivo (o histórico registra o porquê do fim). A data
 * de fim é opcional (default = hoje no UseCase).
 */
final class EncerrarVinculoInput
{
    #[Assert\NotNull(message: 'Informe o vínculo a encerrar.')]
    #[Assert\Positive(message: 'Vínculo inválido.')]
    public ?int $vinculoId = null;

    public ?\DateTimeImmutable $dataFim = null;

    #[Assert\NotBlank(message: 'Informe o motivo do encerramento.')]
    #[Assert\Length(max: 255, maxMessage: 'O motivo pode ter no máximo {{ limit }} caracteres.')]
    public ?string $motivoEncerramento = null;

    public ?string $observacao = null;
}
