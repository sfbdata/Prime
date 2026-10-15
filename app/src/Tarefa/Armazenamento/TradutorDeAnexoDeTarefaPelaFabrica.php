<?php

declare(strict_types=1);

namespace App\Tarefa\Armazenamento;

use App\Shared\EspelhoRemoto\Reconciliacao\TradutorDeAnexoDeTarefa;

/** A regra de `ChavesDeTarefa`, oferecida à reconciliação do espelho sem que Shared importe Tarefa. */
final class TradutorDeAnexoDeTarefaPelaFabrica implements TradutorDeAnexoDeTarefa
{
    public function nomeDoValorDaColuna(?string $valor): ?string
    {
        return ChavesDeTarefa::nomeDoValorDaColuna($valor);
    }
}
