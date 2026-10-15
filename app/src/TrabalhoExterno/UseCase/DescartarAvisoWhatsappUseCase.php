<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeWhatsapp;

/** "Descartar": a mensagem pronta sai da fila sem ser enviada. */
final class DescartarAvisoWhatsappUseCase
{
    public function __construct(
        private readonly GuardaDeWhatsapp $guarda,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, int $aviso): DemandaExterna
    {
        $demanda = $this->guarda->demanda($ctx, $codigo);
        $demanda->descartarAviso($this->guarda->aviso($demanda, $aviso));
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
