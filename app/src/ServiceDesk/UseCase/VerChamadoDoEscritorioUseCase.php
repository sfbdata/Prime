<?php

declare(strict_types=1);

namespace App\ServiceDesk\UseCase;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\DTO\ChamadoDaListaOutput;
use App\ServiceDesk\DTO\ChamadoDetalheOutput;
use App\ServiceDesk\DTO\LinhaDoAndamentoOutput;
use App\ServiceDesk\Entity\HistoricoDoChamado;
use App\ServiceDesk\Exception\ChamadoNaoEncontrado;
use App\ServiceDesk\Repository\ChamadoDeSuporteRepository;
use App\ServiceDesk\Repository\HistoricoDoChamadoRepository;

/** Janela "Chamado #0001": resumo, descrição e ANDAMENTO. Chamado de outro escritório é "não encontrado". */
final class VerChamadoDoEscritorioUseCase
{
    public function __construct(
        private readonly ChamadoDeSuporteRepository $chamados,
        private readonly HistoricoDoChamadoRepository $historicos,
    ) {
    }

    public function executar(Tenant $tenant, int $id): ChamadoDetalheOutput
    {
        $chamado = $this->chamados->doEscritorio($tenant, $id) ?? throw new ChamadoNaoEncontrado('Chamado não encontrado.');

        return new ChamadoDetalheOutput(
            ChamadoDaListaOutput::fromEntity($chamado),
            $chamado->getDescricao(),
            array_map(
                static fn (HistoricoDoChamado $h): LinhaDoAndamentoOutput => new LinhaDoAndamentoOutput($h->getCriadoEm(), $h->getTexto()),
                $this->historicos->doChamado($chamado),
            ),
        );
    }
}
