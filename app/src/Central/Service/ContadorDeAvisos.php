<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\DTO\ContagemDeAvisos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Service\SilencioDoSininho;

/**
 * As contagens do lançador (F1), com a mesma rechecagem de acesso da lista: aviso de pasta que não vejo não conta. Com o
 * sininho em silêncio (preferência do Meu Perfil), só contam os tipos que pedem ação: o lançador não salta nem toca
 * pelo resto, que continua na Caixa.
 */
final class ContadorDeAvisos
{
    public function __construct(
        private readonly AvisosDaCentral $avisos,
        private readonly AcessoAosAvisos $acesso,
        private readonly TiposDeAvisoDaCentral $tipos,
        private readonly ?SilencioDoSininho $silencio = null,
    ) {
    }

    public function contar(User $eu, Tenant $tenant): ContagemDeAvisos
    {
        $naoLidos = 0;
        $urgentes = 0;
        $importantes = 0;
        $memoria = [];
        $soTipos = $this->silencio?->tiposQueContam($eu);
        foreach ($this->avisos->naoLidosAgrupados($eu, $tenant) as $grupo) {
            if (!$grupo['responsavel'] && !$this->acesso->podeVerPasta($grupo['pasta'], $eu, $tenant, $memoria)) {
                continue;
            }
            if ($soTipos !== null && !in_array($grupo['tipo'], $soTipos, true)) {
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
