<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que o envio do relatório de pastas sem processo fez: se saiu, a mensagem para a tela (ou o
 * motivo de não ter saído), quantas pessoas foram avisadas e as contagens enviadas.
 */
final class ResultadoDoEnvioSemProcesso
{
    public function __construct(
        public readonly bool $enviado,
        public readonly string $mensagem,
        public readonly int $avisados = 0,
        public readonly bool $semControladoria = false,
        public readonly ?ResumoSemProcesso $resumo = null,
        public readonly bool $acimaDoTeto = false,
    ) {
    }

    public static function naoEnviado(string $motivo): self
    {
        return new self(false, $motivo);
    }

    /** Envio manual repetido antes de 30 minutos (teto por escritório). */
    public static function acimaDoTeto(string $motivo): self
    {
        return new self(false, $motivo, acimaDoTeto: true);
    }
}
