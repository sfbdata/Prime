<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

final readonly class ResultadoDoTeste
{
    public function __construct(
        public bool $conectou,
        public string $mensagem,
    ) {
    }
}
