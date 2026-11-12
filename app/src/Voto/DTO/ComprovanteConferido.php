<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Resultado da conferência pública de um comprovante: a pauta e o momento em que a unidade votou (nunca a unidade nem a opção). */
final readonly class ComprovanteConferido
{
    public function __construct(
        public string $pauta,
        public \DateTimeImmutable $registradaEm,
    ) {
    }
}
