<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Dados públicos mínimos; não inclui eleitorado ou credenciais individuais. */
final readonly class PortalDaAssembleiaOutput
{
    public function __construct(public string $titulo, public string $codigo)
    {
    }
    public function codigoDeConferencia(): string
    {
        return strtoupper(substr($this->codigo, 0, 4) . '-' . substr($this->codigo, -4));
    }
}
