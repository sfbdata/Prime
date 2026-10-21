<?php

declare(strict_types=1);

namespace App\Contribua\Event;

use App\Contribua\DTO\RetratoDoEspaco;

/**
 * O percentual de uso de um escritório subiu com um envio (arredondado como no desenho). Leva o antes e o
 * depois para quem decide os próprios limites: as automações "Comercial e e-mails" do desenho 05 (aviso
 * em 80, 85 ou 90%, escolhido pelo Master) ouvem este evento, sem depender das faixas do Contribua.
 *
 * Despachado DEPOIS do commit (`EventosDoEspacoAposConfirmar`): envio desfeito não gera aviso.
 */
final readonly class UsoDoEspacoSubiu
{
    public function __construct(
        public int $tenantId,
        public int $percentualAnterior,
        public int $percentualAtual,
        public RetratoDoEspaco $retrato,
    ) {
    }

    /** Cruzou `$limite` (em %) com este envio? */
    public function cruzou(int $limite): bool
    {
        return $this->percentualAnterior < $limite && $this->percentualAtual >= $limite;
    }
}
