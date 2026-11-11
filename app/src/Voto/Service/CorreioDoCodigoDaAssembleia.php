<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Entity\UnidadeDaAssembleia;

interface CorreioDoCodigoDaAssembleia
{
    public function enviar(UnidadeDaAssembleia $unidade, string $codigo): void;
}
