<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/** Interna do motor: a chave confere (true), não confere (false) ou não deu para conferir (null, com motivo). */
final readonly class ResultadoDaChave
{
    public function __construct(
        public ?bool $confere,
        public ?string $motivo = null,
    ) {
    }

    public static function naoConferida(string $motivo): self
    {
        return new self(null, $motivo);
    }
}
