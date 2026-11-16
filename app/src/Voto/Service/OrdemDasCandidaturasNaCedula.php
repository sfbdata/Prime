<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\OrdemDasCandidaturas;
use Random\Engine;
use Random\Randomizer;

/**
 * A ordem das opções de voto na cédula (desenho 18, "Ordem na lista de candidaturas"): "Ordem de registro" (pelo número)
 * ou "Sorteada a cada visualização". A abstenção (ou voto em branco) fica sempre por último e fora do sorteio. O motor
 * do sorteio é injetável só para o teste fixar o resultado.
 */
final class OrdemDasCandidaturasNaCedula
{
    private readonly Randomizer $sorteio;

    public function __construct(?Engine $motor = null)
    {
        $this->sorteio = new Randomizer($motor);
    }

    /** @return list<OpcaoDaPauta> as opções de voto na ordem da pauta e a abstenção por último */
    public function ordenar(PautaDaAssembleia $pauta): array
    {
        $opcoes = $pauta->getOpcoesDeVoto();
        if (OrdemDasCandidaturas::Sorteada === $pauta->getOrdemDasCandidaturas()) {
            $opcoes = $this->sorteio->shuffleArray($opcoes);
        }
        $abstencao = $pauta->getAbstencao();
        if (null !== $abstencao) {
            $opcoes[] = $abstencao;
        }

        return $opcoes;
    }
}
