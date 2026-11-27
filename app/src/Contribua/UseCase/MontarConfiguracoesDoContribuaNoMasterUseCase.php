<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\ConfiguracoesDoContribuaNoMasterView;
use App\Contribua\Service\SolicitanteDaSessao;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Monta, para o painel do Master, as configurações que ficavam na página Contribua (pedido do Farlei, lote 2, L2-M5,
 * 27/11/2026): os mesmos casos de uso da página, com as mesmas rotas e permissões. Nada é gravado aqui.
 *
 * Sem escritório escolhido (o Contribua também não abre assim) ou para quem não é Master, devolve null e o painel não
 * mostra o bloco.
 */
final class MontarConfiguracoesDoContribuaNoMasterUseCase
{
    public function __construct(
        private readonly SolicitanteDaSessao $sessao,
        private readonly MontarPagamentoDoContribuaUseCase $pagamento,
        private readonly ListarPedidosDeEspacoParaOMasterUseCase $pedidos,
        private readonly MontarPainelDaCampanhaUseCase $campanha,
    ) {
    }

    public function executar(): ?ConfiguracoesDoContribuaNoMasterView
    {
        try {
            $quem = $this->sessao->atual();
        } catch (AccessDeniedException) {
            return null;
        }
        if (!$quem->ehMaster) {
            return null;
        }

        return new ConfiguracoesDoContribuaNoMasterView($this->pagamento->executar($quem), $this->pedidos->executar($quem), $this->campanha->executar());
    }
}
