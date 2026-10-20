<?php

declare(strict_types=1);

namespace App\Agenda\DTO;

/** Resposta do modal ao salvar: o id, o dia em que a agenda deve abrir e quantos colegas foram avisados. */
final readonly class CompromissoSalvoOutput
{
    public function __construct(
        public int $id,
        public string $data,
        public int $avisados,
    ) {
    }
}
