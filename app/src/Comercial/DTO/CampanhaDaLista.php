<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * Uma linha da lista de campanhas (dc L.284-285 e L.586): nome, a linha "modelo · público[ · frequência]", a data
 * (a agendada, ou a da confirmação), o total e o estado com o rótulo. As métricas são da fatia 3.
 */
final class CampanhaDaLista
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $sub,
        public readonly string $em,
        public readonly int $total,
        public readonly string $estado,
        public readonly string $rotulo,
    ) {
    }
}
