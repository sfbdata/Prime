<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** A pausa de verdade entre as tentativas da reconciliação (os testes usam uma que não dorme). */
#[AsAlias(id: PausaEntreTentativas::class)]
final class PausaReal implements PausaEntreTentativas
{
    public function aguardar(int $segundos): void
    {
        if ($segundos > 0) {
            sleep($segundos);
        }
    }
}
