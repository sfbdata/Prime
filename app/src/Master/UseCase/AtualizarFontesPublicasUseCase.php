<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Fontes\FontesPublicas;

/**
 * Atualiza os dados públicos do mapa: população das UFs (IBGE) e Quadro da Advocacia (OAB).
 * Usado pelo comando `app:master:atualizar-fontes-publicas`, pela ação "Atualizar dados" do menu ⋮
 * e pela atualização automática do mapa quando o retrato passa de 24 horas. Fonte que falhar mantém o retrato anterior; nada é apagado.
 */
final class AtualizarFontesPublicasUseCase
{
    public function __construct(
        private readonly FontesPublicas $fontes,
    ) {
    }

    /**
     * @param bool $automatico atualização automática do mapa: só fonte vencida (24 h) e fora da espera de 1 h após falha
     *
     * @return array{ibge: bool, oab: bool, emAndamento: bool}
     */
    public function executar(bool $automatico = false): array
    {
        return $this->fontes->atualizar($automatico);
    }
}
