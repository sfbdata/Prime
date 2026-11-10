<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\JustificativaPonto;

/**
 * O resultado do abono das justificativas da equipe no "Abonar selecionadas" (`AnalisarJustificativaUseCase::abonarDaEquipe`,
 * spec ponto-desenho-0811 E5): as abonadas, para o aviso de cada pessoa, e o motivo de cada uma que não foi, pelo id.
 */
final readonly class AbonoDaEquipeOutput
{
    /**
     * @param list<JustificativaPonto> $abonadas
     * @param array<int, string>       $recusas  o motivo, já em texto de tela, pelo id da justificativa
     */
    public function __construct(
        public array $abonadas,
        public array $recusas,
    ) {
    }
}
