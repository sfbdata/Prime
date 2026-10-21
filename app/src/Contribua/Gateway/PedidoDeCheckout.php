<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/**
 * O que o BlueJus pede a um gateway quando o escritório clica em "Quero +N GB".
 *
 * `referencia` identifica o pedido de ponta a ponta: `BJ<conta>-<plano>-<id do pedido>`. Todo adaptador
 * a envia ao gateway por onde ele aceitar (externalReference, metadata, client_reference_id), e todo
 * aviso volta a ela ou ao id da assinatura no gateway.
 */
final readonly class PedidoDeCheckout
{
    public function __construct(
        public string $referencia,
        public int $tenantId,
        public string $planoCodigo,
        public string $planoNome,
        /** Valor mensal, em centavos. */
        public int $valorCentavos,
        public Pagador $pagador,
        /** Para onde o gateway devolve o pagador depois de pagar ("?assinatura=ok" do desenho). */
        public string $urlDeRetorno,
        public string $urlDeCancelamento,
        /** IP do pagador (alguns gateways exigem o do dispositivo, nunca o do servidor). */
        public ?string $ipDoPagador = null,
        /**
         * O link de assinatura que o Master colou para este plano no modal "Pagamento por cartão" (desenho
         * 08). Serve aos gateways de link fixo; o Asaas ignora (cria o checkout pela API).
         */
        public ?string $linkDoPacote = null,
    ) {
    }
}
