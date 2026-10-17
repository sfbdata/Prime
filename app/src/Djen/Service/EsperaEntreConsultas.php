<?php

declare(strict_types=1);

namespace App\Djen\Service;

final class EsperaEntreConsultas implements EsperaEntreConsultasInterface
{
    public function aguardar(int $segundos): void
    {
        if ($segundos > 0) {
            sleep($segundos);
        }
    }
}
