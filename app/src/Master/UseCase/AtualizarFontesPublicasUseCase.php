<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Fontes\FontesPublicas;

/**
 * Atualiza os dados públicos do mapa: população das UFs (IBGE) e Quadro da Advocacia (OAB).
 * Usado pelo cron diário (`app:master:atualizar-fontes-publicas`) e pela ação "Atualizar dados"
 * do menu ⋮. Fonte que falhar mantém o retrato anterior; nada é apagado.
 */
final class AtualizarFontesPublicasUseCase
{
    public function __construct(
        private readonly FontesPublicas $fontes,
    ) {
    }

    /**
     * @return array{ibge: bool, oab: bool}
     */
    public function executar(): array
    {
        return $this->fontes->atualizar();
    }
}
