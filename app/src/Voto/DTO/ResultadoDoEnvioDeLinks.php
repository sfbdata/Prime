<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Um lote de "Enviar links por e-mail": enviados agora, falhas do transporte e o "faltam N" depois do lote. */
final readonly class ResultadoDoEnvioDeLinks
{
    public function __construct(
        public int $enviados,
        public int $falhas,
        public int $faltam,
    ) {
    }
}
