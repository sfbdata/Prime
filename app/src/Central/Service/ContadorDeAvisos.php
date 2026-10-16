<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\DTO\ContagemDeAvisos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** As contagens do lançador (F1), com a mesma rechecagem de acesso da lista: aviso de pasta que não vejo não conta. */
final class ContadorDeAvisos
{
    public function __construct(
        private readonly AvisosDaCentral $avisos,
        private readonly AcessoAosAvisos $acesso,
    ) {
    }

    public function contar(User $eu, Tenant $tenant): ContagemDeAvisos
    {
        $naoLidos = 0;
        $urgentes = 0;
        $memoria = [];
        foreach ($this->avisos->naoLidosAgrupados($eu, $tenant) as $grupo) {
            if (!$grupo['responsavel'] && !$this->acesso->podeVerPasta($grupo['pasta'], $eu, $tenant, $memoria)) {
                continue;
            }
            $naoLidos += $grupo['total'];
            $urgentes += $grupo['urgente'] ? $grupo['total'] : 0;
        }

        return new ContagemDeAvisos($naoLidos, $urgentes, $this->avisos->ultimoId($eu, $tenant));
    }
}
