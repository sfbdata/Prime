<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Uma pastilha de desfecho no detalhe da pessoa (spec §4). O rótulo já vem resolvido — do
 * `ResultadoContato::label()` quando o valor cru do payload é conhecido, ou do próprio valor cru quando
 * não é (nada é descartado em silêncio).
 */
final class DesfechoContatoOutput
{
    public function __construct(
        public readonly string $label,
        public readonly int $quantidade,
    ) {
    }
}
