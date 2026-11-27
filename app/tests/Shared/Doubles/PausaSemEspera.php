<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use App\Shared\EspelhoRemoto\Reconciliacao\PausaEntreTentativas;

/** Não dorme: só guarda as pausas pedidas, para o teste conferir quantas e de quanto. */
final class PausaSemEspera implements PausaEntreTentativas
{
    /** @var list<int> */
    public array $pausas = [];

    public function aguardar(int $segundos): void
    {
        $this->pausas[] = $segundos;
    }
}
