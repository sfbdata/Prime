<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

/** Sorteio com `random_int` (gerador criptográfico), para o código não ser adivinhável pela sequência. */
final class SorteioAleatorioDeCodigoDoDespacho implements SorteioDeCodigoDoDespacho
{
    public function sortear(): string
    {
        $digitos = sprintf('%08d', random_int(0, 99_999_999));

        return substr($digitos, 0, 4) . '.' . substr($digitos, 4);
    }
}
