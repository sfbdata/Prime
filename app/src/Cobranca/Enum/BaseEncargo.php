<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Base de incidência de um encargo (spec "encargos configuráveis em cascata" §3/§4.1): sobre o
 * principal puro (encargo FIXO, não cresce) ou sobre a base composta acumulada até ali (encargo
 * PROGRESSIVO, cresce junto com os encargos anteriores).
 *
 * O que os dados reais provam (Apêndice A da spec): multa e correção incidem sobre o PRINCIPAL
 * puro; quem incide sobre a base composta são os HONORÁRIOS. Ambos configuráveis por carteira.
 */
enum BaseEncargo: string
{
    /** Incide sobre o valor original (principal) puro — encargo fixo. */
    case Principal = 'principal';

    /** Incide sobre a base composta acumulada (principal + encargos anteriores) — encargo progressivo. */
    case Composta = 'composta';

    public function label(): string
    {
        return match ($this) {
            self::Principal => 'Sobre o valor original (fixa)',
            self::Composta => 'Sobre o valor com encargos (progressiva)',
        };
    }
}
