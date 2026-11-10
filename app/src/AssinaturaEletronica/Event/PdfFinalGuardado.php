<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Event;

/**
 * O PDF final com selo de um documento foi guardado (contrato da etapa 3, seção 2, item 8). Despachado DEPOIS do COMMIT
 * que grava a chave no documento; quem ouve (o vínculo à pasta) relê o documento pelo id.
 */
final class PdfFinalGuardado
{
    public function __construct(
        public readonly int $documentoId,
    ) {
    }
}
