<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\GuardaDeExecucao;

/**
 * Marcar/desmarcar um item do "CHECKLIST DE EXECUÇÃO". Quem: Master e Equipe (e, na F3, o
 * Convidado com "Registrar andamento"). Só em execução. O chat recebe "Etapa concluída: …" ou
 * "Etapa reaberta: …" como andamento.
 */
final class AlternarItemDoChecklistUseCase
{
    public function __construct(
        private readonly GuardaDeExecucao $guarda,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, int $indice): DemandaExterna
    {
        [$demanda, $ctx] = $this->guarda->demandaEmExecucao($ctx, $codigo);
        $demanda->alternarItemDoChecklist($indice, $ctx->autor());
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
