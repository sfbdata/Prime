<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\PedidoDeEspacoDoEscritorioView;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\PedidoDeEspaco;
use App\Contribua\Repository\PedidoDeEspacoRepository;
use App\Contribua\Service\SituacaoDoEspaco;

/**
 * A situação do pedido de espaço do escritório da sessão, para a caixa do topo do Meu espaço (N4): nenhum, em análise
 * (GB e data) ou opções liberadas (origem). Liberação (Master ou 97% de uso) vale mais que pedido pendente; pedido
 * recusado volta a "nenhum" (o escritório pode pedir de novo).
 */
final class VerPedidoDeEspacoDoEscritorioUseCase
{
    public function __construct(
        private readonly PedidoDeEspacoRepository $pedidos,
        private readonly SituacaoDoEspaco $situacao,
    ) {
    }

    public function executar(Solicitante $quem): PedidoDeEspacoDoEscritorioView
    {
        $retrato = $this->situacao->doEscritorio($quem->tenantId());
        if ($retrato->opcoesLiberadas) {
            return new PedidoDeEspacoDoEscritorioView(PedidoDeEspacoDoEscritorioView::LIBERADO, liberadaPor: $retrato->liberadaPor);
        }

        $pendente = $this->pedidos->pendenteDoEscritorio($quem->tenantId());
        if ($pendente instanceof PedidoDeEspaco) {
            return new PedidoDeEspacoDoEscritorioView(PedidoDeEspacoDoEscritorioView::PENDENTE, $pendente->getGbDesejado(), $pendente->getCriadoEm());
        }

        return new PedidoDeEspacoDoEscritorioView(PedidoDeEspacoDoEscritorioView::NENHUM);
    }
}
