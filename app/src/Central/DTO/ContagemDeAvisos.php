<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** Para o lançador sem carregar a lista (F1): não lidos, urgentes não lidos e o maior id (a tela recarrega quando muda). */
final class ContagemDeAvisos
{
    public function __construct(
        public readonly int $naoLidos,
        public readonly int $urgentes,
        public readonly int $ultimo,
    ) {
    }
}
