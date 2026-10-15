<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeWhatsapp;

/** Pausar/Reativar um contato conectado: pausado, ele não recebe avisos de fase. */
final class AlternarContatoWhatsappUseCase
{
    public function __construct(
        private readonly GuardaDeWhatsapp $guarda,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, int $contato): DemandaExterna
    {
        [$demanda, $ctx] = $this->guarda->demanda($ctx, $codigo);
        $this->guarda->contato($demanda, $contato)->alternarAtivo();
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
