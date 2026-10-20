<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

/** O boleto registrado no banco: o id dele, a linha digitável, o PDF e o Pix do boleto (quando vem). */
final readonly class BoletoEmitido
{
    public function __construct(
        public string $idNoBanco,
        public string $linhaDigitavel,
        /** Sempre https:// (o adaptador confere antes de devolver). */
        public string $url,
        public ?string $pixCopiaECola,
    ) {
    }
}
