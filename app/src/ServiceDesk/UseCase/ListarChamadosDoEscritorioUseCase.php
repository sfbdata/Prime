<?php

declare(strict_types=1);

namespace App\ServiceDesk\UseCase;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\DTO\ChamadoDaListaOutput;
use App\ServiceDesk\DTO\ChamadosDoEscritorioOutput;
use App\ServiceDesk\Enum\StatusDoChamado;
use App\ServiceDesk\Repository\ChamadoDeSuporteRepository;

/** Lista e KPIs de "Meus chamados": a contagem é do escritório todo, a lista obedece ao filtro e à busca. */
final class ListarChamadosDoEscritorioUseCase
{
    public function __construct(private readonly ChamadoDeSuporteRepository $chamados)
    {
    }

    public function executar(Tenant $tenant, ?StatusDoChamado $status = null, string $busca = ''): ChamadosDoEscritorioOutput
    {
        $contagem = $this->chamados->contarPorStatus($tenant);

        return new ChamadosDoEscritorioOutput(
            array_map(ChamadoDaListaOutput::fromEntity(...), $this->chamados->listarDoEscritorio($tenant, $status, $busca)),
            $contagem,
            array_sum($contagem),
        );
    }
}
