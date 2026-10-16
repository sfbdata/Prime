<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeConvites;

/**
 * "Revogar acesso": o convidado perde o acesso a esta demanda imediatamente. O histórico e os
 * arquivos que ele enviou ficam. Mesma permissão de quem convida.
 */
final class RevogarConviteUseCase
{
    public function __construct(
        private readonly GuardaDeConvites $guarda,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, int $convite): DemandaExterna
    {
        [$demanda, $ctx] = $this->guarda->demanda($ctx, $codigo);
        $demanda->revogarConvite($this->guarda->convite($demanda, $convite), $ctx->autor());
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
