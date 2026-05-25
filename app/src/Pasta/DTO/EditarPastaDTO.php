<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

final readonly class EditarPastaDTO
{
    public function __construct(
        public string $nup,
        public ?string $nomeCliente = null,
        public ?string $nomeAcao = null,
        public string $situacao = '',
    ) {}
}
