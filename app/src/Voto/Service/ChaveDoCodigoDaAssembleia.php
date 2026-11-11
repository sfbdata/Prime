<?php

declare(strict_types=1);

namespace App\Voto\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ChaveDoCodigoDaAssembleia
{
    public function __construct(#[Autowire('%kernel.secret%')] private readonly string $segredo)
    {
    }

    public function novo(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function hash(string $codigo, string $desafio): string
    {
        return hash_hmac('sha256', 'voto-codigo:' . $desafio . ':' . $codigo, $this->segredo);
    }
}
