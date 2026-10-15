<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeWhatsapp;

/** "Enviar pelo WhatsApp": o navegador abre o wa.me e a mensagem passa para "ENVIADAS", com quem e quando. */
final class MarcarAvisoEnviadoUseCase
{
    public function __construct(
        private readonly GuardaDeWhatsapp $guarda,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, int $aviso): DemandaExterna
    {
        [$demanda, $ctx] = $this->guarda->demanda($ctx, $codigo);
        $this->guarda->aviso($demanda, $aviso)->marcarEnviado($ctx->autor());
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
