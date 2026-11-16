<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** Uma linha do painel de lista de um KPI: a votação (pauta), a assembleia dela e a participação. */
final readonly class LinhaDoKpi
{
    /**
     * @param string $situacao aberta | suspensa | encerrada | cancelada
     */
    public function __construct(
        public string $titulo,
        public string $assembleia,
        public int $votantes,
        public int $aptos,
        public ?int $segundos,
        public string $situacao,
    ) {
    }
}
