<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

/** A cobrança criada no banco: o id dele e o PIX copia e cola (o QR é desenhado a partir dele). */
final readonly class CobrancaPix
{
    public function __construct(
        public string $idNoBanco,
        public string $copiaECola,
    ) {
    }
}
