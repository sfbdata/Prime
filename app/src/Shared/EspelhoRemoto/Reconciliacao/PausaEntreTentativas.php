<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

/** Pausa entre as tentativas de uma consulta que falhou; substituível nos testes para não dormirem. */
interface PausaEntreTentativas
{
    public function aguardar(int $segundos): void;
}
