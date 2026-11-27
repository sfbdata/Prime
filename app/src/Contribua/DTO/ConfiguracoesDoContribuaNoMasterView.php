<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/**
 * As configurações só do Master que saíram da página Contribua para o painel do Master (pedido do Farlei, lote 2,
 * L2-M5, 27/11/2026): o pagamento por cartão (gateway e últimas assinaturas) e os pedidos de espaço, na seção "Planos e
 * armazenamento"; o modo campanha, na seção "Comercial e e-mails". A integração bancária se monta sozinha
 * (`contribua_integracao_bancaria()`).
 */
final readonly class ConfiguracoesDoContribuaNoMasterView
{
    /** @param array<string, mixed> $pagamento o mesmo do `MontarPagamentoDoContribuaUseCase` (o parcial lê `pagamento.master`) */
    public function __construct(
        public array $pagamento,
        public PainelDePedidosDeEspacoView $pedidos,
        public ?PainelDaCampanhaView $campanha,
    ) {
    }
}
