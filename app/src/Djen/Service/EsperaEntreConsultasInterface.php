<?php

declare(strict_types=1);

namespace App\Djen\Service;

/**
 * Pausa entre consultas ao DJEN. Existe para o teste não dormir de verdade: a captação diária
 * espera a janela do limite de requisições (20 por IP, medido em 16/10/2026) passar.
 */
interface EsperaEntreConsultasInterface
{
    public function aguardar(int $segundos): void;
}
