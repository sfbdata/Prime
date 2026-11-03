<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

/**
 * O resultado da seleção: quantos passaram nos critérios baratos (e quantos bytes), o lote já com o
 * SHA local conferido, do mais antigo para o mais novo, e quantos ficaram de fora por motivo.
 */
final readonly class SelecaoParaLiberar
{
    /**
     * @param list<CandidatoALiberar> $lote
     * @param array<string, int>      $fora motivo => quantidade
     */
    public function __construct(
        public int $candidatos,
        public int $bytesDosCandidatos,
        public array $lote,
        public array $fora,
    ) {
    }

    public function bytesDoLote(): int
    {
        return array_sum(array_map(static fn (CandidatoALiberar $c): int => $c->tamanhoBytes, $this->lote));
    }
}
