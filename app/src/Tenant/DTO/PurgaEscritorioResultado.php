<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

/**
 * Resultado da purga (ou simulação de purga) de um escritório: quantas linhas foram
 * (ou seriam) apagadas por tabela e quantos arquivos em disco foram removidos.
 */
final class PurgaEscritorioResultado
{
    /**
     * @param array<string,int> $linhasPorTabela tabela => nº de linhas apagadas (ou que seriam)
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $nome,
        public readonly bool $simulado,
        public readonly array $linhasPorTabela,
        public readonly int $arquivosRemovidos,
    ) {
    }

    public function totalLinhas(): int
    {
        return array_sum($this->linhasPorTabela);
    }
}
