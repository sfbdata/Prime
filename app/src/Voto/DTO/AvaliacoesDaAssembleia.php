<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * A aba "Avaliações" da assembleia: as avaliações com as pendentes primeiro. `podeTornarApto` fica falso quando alguma
 * pauta já encerrou (o botão "Tornar apto(a)" aparece desabilitado, como o desenho faz com `nAlt`).
 */
final readonly class AvaliacoesDaAssembleia
{
    /** @param list<AvaliacaoNaLista> $avaliacoes */
    public function __construct(
        public AssembleiaNoPainel $assembleia,
        public array $avaliacoes,
        public bool $podeTornarApto,
    ) {
    }
}
