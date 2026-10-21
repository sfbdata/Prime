<?php

declare(strict_types=1);

namespace App\Profile\DTO;

/** O que o resumo diário fez num escritório, para a tabela do comando (spec docs/specs/resumo-diario-por-email.md). */
final class ResultadoDoResumoDiario
{
    public function __construct(
        public readonly int $enviados = 0,
        public readonly int $semNada = 0,
        public readonly int $desligados = 0,
        public readonly int $jaEnviados = 0,
        public readonly int $descadastrados = 0,
        public readonly int $falhas = 0,
        public readonly ?string $motivo = null,
    ) {
    }

    public static function semEnvio(string $motivo): self
    {
        return new self(motivo: $motivo);
    }
}
