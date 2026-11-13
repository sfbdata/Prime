<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Dados exclusivos da participação. Nenhuma escolha ou credencial de acesso. */
final readonly class ComprovanteDeParticipacao
{
    public function __construct(
        public string $assembleia,
        public string $pauta,
        public string $unidade,
        public \DateTimeImmutable $registradaEm,
        public string $codigo,
    ) {
    }
}
