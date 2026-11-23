<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * A recusa do envio da batida (spec ponto-desenho-0811 §5 E13 e E14): o status HTTP e a mensagem que a pessoa lê.
 */
final readonly class RecusaDoEnvio
{
    /** O português da recusa (a mensagem de sempre); quem a leva à tela traduz o {@see self::$texto}. */
    public string $mensagem;

    public function __construct(
        public int $status,
        public TextoDoPonto $texto,
    ) {
        $this->mensagem = $texto->portugues;
    }
}
