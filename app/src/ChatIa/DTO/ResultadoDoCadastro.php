<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\Pasta\Entity\Pasta;

/** O que o clique em Confirmar criou: `tipo` ('cliente' ou 'pasta') e o id. Com pasta, ela vem junto (para o gatilho do Drive). */
final readonly class ResultadoDoCadastro
{
    public function __construct(
        public string $tipo,
        public int $id,
        public ?Pasta $pasta = null,
    ) {
    }
}
