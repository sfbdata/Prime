<?php

declare(strict_types=1);

namespace App\Processo\Integracao;

/** Sem PJe/MNI nesta instalação (P5a pendente com o dono). */
final class IntegracaoComPjeNaoConfigurada implements IntegracaoComPjeInterface
{
    public function estaConfigurada(): bool
    {
        return false;
    }
}
