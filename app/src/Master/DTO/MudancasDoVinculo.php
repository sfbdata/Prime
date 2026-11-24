<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Master\Entity\ConvenioVinculo;

/** O que a {@see \App\Master\Service\RegraDosVinculosDeConvenio} mudou num escritório. */
final class MudancasDoVinculo
{
    public ?ConvenioVinculo $novo = null;
    public int $encerrados = 0;
    public int $provasPendentes = 0;
    public int $provasDeVolta = 0;

    public function houveMudanca(): bool
    {
        return $this->novo !== null || $this->encerrados + $this->provasPendentes + $this->provasDeVolta > 0;
    }
}
