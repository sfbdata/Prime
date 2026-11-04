<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

/** O que a restauração fez (ou faria, na simulação) com cada linha aberta, por resultado. */
final class ResumoDaRestauracao
{
    /** @var array<string, int> */
    private array $contagem = [];

    public function contar(string $resultado): void
    {
        $this->contagem[$resultado] = ($this->contagem[$resultado] ?? 0) + 1;
    }

    public function quantos(string $resultado): int
    {
        return $this->contagem[$resultado] ?? 0;
    }

    /** @return array<string, int> resultado => quantidade */
    public function contagem(): array
    {
        ksort($this->contagem);

        return $this->contagem;
    }
}
