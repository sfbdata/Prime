<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeWhatsapp;

/** "Avisar fase atual": prepara de novo a mensagem da fase em que a demanda está, para os contatos ativos. */
final class AvisarFaseAtualUseCase
{
    public function __construct(
        private readonly GuardaDeWhatsapp $guarda,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo): DemandaExterna
    {
        $demanda = $this->guarda->demanda($ctx, $codigo);
        $demanda->avisarFaseAtual();
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
