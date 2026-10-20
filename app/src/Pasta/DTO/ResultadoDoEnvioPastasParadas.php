<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que o envio do relatório mensal de pastas paradas fez: se saiu, a mensagem (ou o motivo de não
 * ter saído), quantos avisos foram (ou seriam, no --dry-run) gravados e quantas pastas estão paradas.
 */
final readonly class ResultadoDoEnvioPastasParadas
{
    public function __construct(
        public bool $enviado,
        public string $mensagem,
        public int $avisados = 0,
        public int $paradas = 0,
    ) {
    }

    public static function naoEnviado(string $motivo): self
    {
        return new self(false, $motivo);
    }
}
