<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * O painel de unidades de uma pauta (aba Acompanhar de /voto): título da pauta, da assembleia, a situação (chip) e as
 * unidades com o estado de cada uma. Sem nome de responsável, voto ou hora.
 */
final readonly class PainelDeUnidadesDaPauta
{
    /**
     * @param string                $situacaoDaPauta publicada | aberta | encerrada
     * @param string                $ciclo           normal | suspensa | cancelada
     * @param list<UnidadeNoPainel> $unidades
     */
    public function __construct(
        public string $tituloDaPauta,
        public string $tituloDaAssembleia,
        public string $situacaoDaPauta,
        public string $ciclo,
        public array $unidades,
    ) {
    }

    public function contar(string $situacao): int
    {
        return \count(array_filter($this->unidades, static fn (UnidadeNoPainel $u): bool => $situacao === $u->situacao));
    }

    /** Aptos: as que votaram mais as que ainda não votaram. */
    public function aptos(): int
    {
        return $this->contar(UnidadeNoPainel::VOTOU) + $this->contar(UnidadeNoPainel::NAO_VOTOU);
    }
}
