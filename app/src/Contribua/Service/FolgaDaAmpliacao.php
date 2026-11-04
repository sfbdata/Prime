<?php

declare(strict_types=1);

namespace App\Contribua\Service;

/**
 * Quanto a ampliação automática ainda deixa o escritório passar do limite: o tamanho de uma dobra, ou zero. É o que o
 * `ArmazenamentoComCota` pergunta quando um envio não cabe (implementado por `AmpliarEspacoAutomaticamenteUseCase`).
 */
interface FolgaDaAmpliacao
{
    public function folgaDaAmpliacao(int $tenantId): int;
}
