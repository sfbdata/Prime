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

    /** @var list<array{candidato: CandidatoALiberar, motivo: string}> */
    private array $comCopiaNosLiberados = [];

    public function liberado(CandidatoALiberar $candidato): void
    {
        $this->liberados[] = $candidato;
    }

    public function pulado(string $motivo, ?CandidatoALiberar $candidato = null): void
    {
        $this->pulados[$motivo] = ($this->pulados[$motivo] ?? 0) + 1;
        if ($candidato !== null && \in_array($motivo, LiberadorDeDisco::MOTIVOS_COM_COPIA_NOS_LIBERADOS, true)) {
            $this->comCopiaNosLiberados[] = ['candidato' => $candidato, 'motivo' => $motivo];
        }
    }

    /**
     * Os pulados que deixaram a cópia nos liberados com a linha aberta (vão ao manifesto).
     *
     * @return list<array{candidato: CandidatoALiberar, motivo: string}>
     */
    public function comCopiaNosLiberados(): array
    {
        return $this->comCopiaNosLiberados;
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
