<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

/** A selfie depois da regravação pelo GD (contrato da etapa 7, seção 6): o JPEG que vai ser cifrado e o sha256 dele. */
final readonly class SelfieRegravada
{
    public function __construct(
        public string $jpeg,
        public string $sha256,
        public int $largura,
        public int $altura,
    ) {
    }
}
