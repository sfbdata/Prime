<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para resolver uma pendência de Revisão de Pessoa Cobrada (SPEC §8). O gestor registra a
 * decisão (manter ou substituir a pessoa cobrada). A revisão é resolvida por id + tenant (guarda
 * multi-tenant) no ResolverRevisaoUseCase — aqui só se valida presença e formato.
 */
final class ResolverRevisaoInput
{
    #[Assert\NotNull(message: 'Informe a revisão.')]
    #[Assert\Positive(message: 'Revisão inválida.')]
    public ?int $revisaoId = null;

    #[Assert\NotBlank(message: 'Informe a resolução da revisão.')]
    public ?string $resolucao = null;
}
