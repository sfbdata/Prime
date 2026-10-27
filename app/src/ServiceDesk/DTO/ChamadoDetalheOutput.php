<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

final class ChamadoDetalheOutput
{
    /** @param list<LinhaDoAndamentoOutput> $andamento do mais antigo ao mais novo */
    public function __construct(
        public readonly ChamadoDaListaOutput $resumo,
        public readonly string $descricao,
        public readonly array $andamento,
    ) {
    }
}
