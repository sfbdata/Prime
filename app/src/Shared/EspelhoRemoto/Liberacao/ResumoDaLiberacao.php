<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

/** O fim de um lote executado: os liberados (para o manifesto) e os pulados por motivo. */
final class ResumoDaLiberacao
{
    /** @var list<CandidatoALiberar> */
    private array $liberados = [];

    /** @var array<string, int> */
    private array $pulados = [];

    public function liberado(CandidatoALiberar $candidato): void
    {
        $this->liberados[] = $candidato;
    }

    public function pulado(string $motivo): void
    {
        $this->pulados[$motivo] = ($this->pulados[$motivo] ?? 0) + 1;
    }

    /** @return list<CandidatoALiberar> */
    public function liberados(): array
    {
        return $this->liberados;
    }

    public function bytesLiberados(): int
    {
        return array_sum(array_map(static fn (CandidatoALiberar $c): int => $c->tamanhoBytes, $this->liberados));
    }

    /** @return array<string, int> motivo => quantidade */
    public function pulados(): array
    {
        ksort($this->pulados);

        return $this->pulados;
    }
}
