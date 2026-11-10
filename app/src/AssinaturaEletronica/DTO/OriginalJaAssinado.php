<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Service\ConferenciaDePdf\ResultadoDaConferencia;

/**
 * O PDF enviado no rascunho que já chegou com assinatura digital (etapa 5, bloco 5A; o `pres` do desenho, dc:373): os
 * bytes exatos, o SHA-256 deles (o `pres.chave`, que entra no parágrafo de dc:374) e a conferência do motor.
 */
final readonly class OriginalJaAssinado
{
    private function __construct(
        public string $bytes,
        public string $sha256,
        public ResultadoDaConferencia $conferencia,
    ) {
    }

    /** Nulo quando o PDF não traz assinatura digital: aí não é "já assinado" e nada muda no rascunho. */
    public static function de(string $bytes, ResultadoDaConferencia $conferencia): ?self
    {
        return $conferencia->temAssinatura() ? new self($bytes, hash('sha256', $bytes), $conferencia) : null;
    }
}
