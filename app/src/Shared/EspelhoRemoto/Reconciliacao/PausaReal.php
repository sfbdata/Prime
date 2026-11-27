<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

final class PausaReal implements PausaEntreTentativas
{
    public function aguardar(int $segundos): void
    {
        if ($segundos > 0) {
            sleep($segundos);
        }
    }
}
