<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * A recusa do envio da batida (spec ponto-desenho-0811 §5 E13 e E14): o status HTTP e a mensagem que a pessoa lê.
 */
final readonly class RecusaDoEnvio
{
    public function __construct(
        public int $status,
        public string $mensagem,
    ) {
    }
}
