<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da judicialização de um Caso de Cobrança (SPEC §16). O gestor informa o caso a
 * judicializar e a Pasta judicial EXISTENTE a vincular; ambos são resolvidos por id + tenant
 * (guarda multi-tenant) no JudicializarCasoUseCase — aqui só se valida presença e formato dos ids.
 */
final class JudicializarCasoInput
{
    #[Assert\NotNull(message: 'Informe o caso de cobrança.')]
    #[Assert\Positive(message: 'Caso de cobrança inválido.')]
    public ?int $casoId = null;

    #[Assert\NotNull(message: 'Informe a pasta judicial.')]
    #[Assert\Positive(message: 'Pasta judicial inválida.')]
    public ?int $pastaId = null;
}
