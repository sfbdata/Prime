<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\ChatIa\Enum\PeriodoDaConsulta;
use App\Tarefa\Enum\AbaMetas;

/**
 * O resultado da consulta de prazos e metas da pessoa ({@see \App\ChatIa\UseCase\ConsultarPrazosEMetasUseCase}). `total` é
 * quantas metas do período a I.A pode ver (já sem as de segredo de justiça); `metas` são as primeiras delas pelo prazo, até o
 * teto. `omitidasPorSegredo` diz só QUANTAS saíram por segredo de justiça, nunca quais.
 */
final readonly class PrazosEMetasOutput
{
    /** @param list<MetaDoPrazoOutput> $metas */
    public function __construct(
        public PeriodoDaConsulta $periodo,
        public AbaMetas $papel,
        public \DateTimeImmutable $hoje,
        public int $total,
        public int $omitidasPorSegredo,
        public array $metas,
    ) {
    }
}
