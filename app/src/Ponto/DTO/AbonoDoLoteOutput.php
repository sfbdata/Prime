<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Exception\AnaliseDeJustificativaRecusadaException;

/**
 * O que o "aprovar todos" gravou: as justificativas abonadas (para os avisos do sininho) e quantas o colaborador
 * excluiu antes de a trava chegar a elas.
 */
final class AbonoDoLoteOutput
{
    /**
     * @param list<JustificativaPonto> $abonadas
     */
    public function __construct(
        public readonly array $abonadas,
        public readonly int $excluidas,
    ) {
    }

    /** O aviso da tela para as que sumiram; null quando nenhuma sumiu. */
    public function avisoDasExcluidas(): ?string
    {
        if ($this->excluidas === 0) {
            return null;
        }

        return $this->excluidas === 1
            ? AnaliseDeJustificativaRecusadaException::MENSAGEM_EXCLUIDA
            : sprintf('%d justificativas deste lote foram excluídas pelo colaborador.', $this->excluidas);
    }
}
