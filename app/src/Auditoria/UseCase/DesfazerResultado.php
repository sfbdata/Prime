<?php

declare(strict_types=1);

namespace App\Auditoria\UseCase;

final readonly class DesfazerResultado
{
    public function __construct(
        public bool $sucesso,
        public ?string $erro = null,
        public bool $truncado = false,
    ) {}
}
