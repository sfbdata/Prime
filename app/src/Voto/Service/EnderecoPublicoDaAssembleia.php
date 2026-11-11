<?php

declare(strict_types=1);

namespace App\Voto\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Endereço canônico configurado, independente do Host enviado na requisição. */
final class EnderecoPublicoDaAssembleia
{
    public function __construct(#[Autowire('%env(DEFAULT_URI)%')] private readonly string $base)
    {
    }

    public function gerar(string $codigo): string
    {
        return rtrim($this->base, '/') . '/voto/v/' . $codigo;
    }
}
