<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Um elemento DER (o `tlv` do protótipo): etiqueta, onde começa, onde começa o valor e onde termina, no buffer lido.
 */
final readonly class NoDer
{
    public function __construct(
        public int $etiqueta,
        public int $inicio,
        public int $valor,
        public int $fim,
    ) {
    }

    public function construido(): bool
    {
        return ($this->etiqueta & 0x20) !== 0;
    }
}
