<?php

declare(strict_types=1);

namespace App\Voto\DTO;

final readonly class DivulgacaoDaAssembleiaOutput
{
    public function __construct(public int $id, public string $titulo, public string $codigo, public string $link)
    {
    }

    /** Identifica o acesso ao evento; não é um selo de integridade das regras da votação. */
    public function codigoDeConferencia(): string
    {
        return strtoupper(substr($this->codigo, 0, 4) . '-' . substr($this->codigo, -4));
    }
}
