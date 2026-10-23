<?php

declare(strict_types=1);

namespace App\Auth\DTO;

readonly class AceitarConviteEscritorioSemContaInput
{
    public function __construct(
        public string $token,
        public string $fullName,
        public string $senha,
        public bool $aceiteTermos = false,
        public string $ip = '',
        public ?string $userAgent = null,
        /** Valor cru do campo Gênero (spec genero-no-cadastro); só `feminino` ou `masculino` passam. */
        public string $genero = '',
    ) {}
}
