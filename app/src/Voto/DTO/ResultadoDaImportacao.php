<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Relatório da importação da lista de unidades: quantas entraram, as linhas inválidas (número da linha no texto
 * colado e o motivo) e as duplicadas (mesmo bloco e unidade de outra linha ou de quem já estava na lista).
 */
final readonly class ResultadoDaImportacao
{
    /**
     * @param list<array{linha: int, motivo: string}> $invalidas
     * @param list<int>                               $duplicadas
     */
    public function __construct(
        public int $importadas,
        public array $invalidas,
        public array $duplicadas,
    ) {
    }

    public function total(): int
    {
        return $this->importadas + \count($this->invalidas) + \count($this->duplicadas);
    }
}
