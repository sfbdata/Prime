<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\FaseWhatsapp;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeWhatsapp;

/** Ligar/desligar uma fase para um contato (as caixinhas de fase de cada contato). */
final class AlternarFaseDoContatoUseCase
{
    public function __construct(
        private readonly GuardaDeWhatsapp $guarda,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, int $contato, string $fase): DemandaExterna
    {
        [$demanda, $ctx] = $this->guarda->demanda($ctx, $codigo);
        $faseWhatsapp = FaseWhatsapp::tryFrom($fase) ?? throw new DadosInvalidos('Fase desconhecida.');
        $this->guarda->contato($demanda, $contato)->alternarFase($faseWhatsapp);
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
