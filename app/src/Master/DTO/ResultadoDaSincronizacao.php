<?php

declare(strict_types=1);

namespace App\Master\DTO;

/** O total de uma volta da sincronização dos vínculos de convênio, para o log da tarefa diária. */
final class ResultadoDaSincronizacao
{
    public int $escritorios = 0;
    public int $criados = 0;
    public int $encerrados = 0;
    public int $provasPendentes = 0;
    public int $provasDeVolta = 0;

    public function somar(MudancasDoVinculo $mudancas): void
    {
        ++$this->escritorios;
        $this->criados += $mudancas->novo !== null ? 1 : 0;
        $this->encerrados += $mudancas->encerrados;
        $this->provasPendentes += $mudancas->provasPendentes;
        $this->provasDeVolta += $mudancas->provasDeVolta;
    }
}
