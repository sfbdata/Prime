<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/** O que o editor do banco de modelos envia: valores crus, validados pelo UseCase. */
final readonly class ModeloDePecaInput
{
    public function __construct(
        public string $titulo,
        public string $materia,
        public string $tipo,
        public string $corpo,
    ) {
    }
}
