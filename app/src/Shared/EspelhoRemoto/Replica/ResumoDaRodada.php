<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Replica;

/** O que uma rodada do worker fez. Só contagens — nada de nome de arquivo. */
final class ResumoDaRodada
{
    public int $reivindicados = 0;
    public int $enviados = 0;
    public int $jaEstavamNoEspelho = 0;
    public int $superados = 0;
    public int $descartados = 0;
    public int $reagendados = 0;
    public int $falharam = 0;

    /** @return array<string, int> */
    public function comoLista(): array
    {
        return [
            'reivindicados'         => $this->reivindicados,
            'enviados'              => $this->enviados,
            'já estavam no espelho' => $this->jaEstavamNoEspelho,
            'superados'             => $this->superados,
            'descartados'           => $this->descartados,
            'reagendados'           => $this->reagendados,
            'falharam'              => $this->falharam,
        ];
    }
}
