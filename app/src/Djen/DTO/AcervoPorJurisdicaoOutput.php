<?php

declare(strict_types=1);

namespace App\Djen\DTO;

final class AcervoPorJurisdicaoOutput
{
    /**
     * @param array<string, array{total: int, orgaos: array<string, int>}> $grupos chave do grupo => total e processos por órgão
     * @param list<LinhaDoProcessoDaOab>                                    $linhas processos do recorte escolhido, do movimento mais recente ao mais antigo
     */
    public function __construct(
        public readonly array $grupos,
        public readonly int $totalDeOrgaos,
        public readonly array $linhas,
        public readonly bool $mostrarLista,
    ) {
    }
}
