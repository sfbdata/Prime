<?php

declare(strict_types=1);

namespace App\Central\Exception;

/** Duas pessoas mexeram na mesma participação no mesmo instante (ex.: adicionar a mesma pessoa duas vezes): 409. */
final class ParticipacaoAlteradaAoMesmoTempoException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('O grupo mudou neste instante. Atualize e tente de novo.');
    }
}
