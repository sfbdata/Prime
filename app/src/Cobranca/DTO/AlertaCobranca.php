<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\TipoAlerta;

/**
 * Alerta operacional derivado de um Caso de Cobrança (SPEC §14, invariável 28). Valor imutável
 * calculado por `AlertasCobranca` a partir de fatos do caso — nunca persistido. Carrega o tipo e uma
 * descrição pronta para exibição; o humano decide o que fazer.
 */
final class AlertaCobranca
{
    public function __construct(
        public readonly TipoAlerta $tipo,
        public readonly string $descricao,
    ) {
    }
}
