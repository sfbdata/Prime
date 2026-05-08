<?php

declare(strict_types=1);

namespace App\Auth\DTO;

readonly class AceitarConvitePlataformaInput
{
    public function __construct(
        public string $token,
        public string $fullName,
        public string $senha,
        public string $oabNumero,
        public string $oabUf,
    ) {}
}
