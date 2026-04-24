<?php
declare(strict_types=1);
namespace App\Profile\DTO;

final class AtualizarStatusInput
{
    public function __construct(
        public readonly ?string $status,
    ) {
    }
}
