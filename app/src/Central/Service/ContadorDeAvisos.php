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
        private readonly TiposDeAvisoDaCentral $tipos,
    ) {
    }

    public function contar(User $eu, Tenant $tenant): ContagemDeAvisos
    {
        $naoLidos = 0;
        $urgentes = 0;
        $importantes = 0;
        $memoria = [];
        foreach ($this->avisos->naoLidosAgrupados($eu, $tenant) as $grupo) {
            if (!$grupo['responsavel'] && !$this->acesso->podeVerPasta($grupo['pasta'], $eu, $tenant, $memoria)) {
                continue;
            }
            $nivel = $this->tipos->para($grupo['tipo'])['nivel'];
            $naoLidos += $grupo['total'];
            $urgentes += $nivel >= 3 ? $grupo['total'] : 0;
            $importantes += $nivel >= 2 ? $grupo['total'] : 0;
        }

        return new ContagemDeAvisos($naoLidos, $urgentes, $this->avisos->ultimoId($eu, $tenant), $importantes);
    }
}
