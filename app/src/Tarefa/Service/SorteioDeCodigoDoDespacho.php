<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

/**
 * Sorteia o código do registro do despacho (D1, C13): 8 dígitos no formato `XXXX.XXXX`. É uma interface para o
 * teste do UseCase controlar o sorteio (colisão e nova tentativa).
 */
interface SorteioDeCodigoDoDespacho
{
    public function sortear(): string;
}
