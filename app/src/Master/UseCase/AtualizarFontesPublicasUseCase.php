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
     * @param bool $soSeVencido atualização automática do mapa: não busca se o retrato tem menos de 24 h
     *
     * @return array{ibge: bool, oab: bool, emAndamento: bool}
     */
    public function executar(bool $soSeVencido = false): array
    {
        return $this->fontes->atualizar($soSeVencido);
    }
}
