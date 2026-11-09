<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * O voto da unidade numa pauta, vindo da cédula pública (`POST /votar/{token}/pauta/{pauta}`). O token identifica a
 * unidade; a pauta e a opção são ids e só valem se forem da assembleia da unidade.
 */
final readonly class RegistrarVotoInput
{
    public function __construct(
        public string $token,
        public int $pautaId,
        public int $opcaoId,
    ) {
    }
}
