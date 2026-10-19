<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/**
 * Um gateway de cartão para a assinatura mensal de espaço (desenho 08: Mercado Pago, Stripe, Asaas,
 * Pagar.me). Uma implementação por gateway, cada uma num adaptador; o resto do Contribua só conhece esta
 * interface.
 *
 * Regras que todo adaptador cumpre (provadas por `TesteDeContratoDoGatewayDeAssinatura`):
 *  - número, validade e CVV do cartão NUNCA chegam ao servidor: ou o gateway hospeda a página
 *    (REDIRECIONAR) ou tokeniza no navegador (FORMULARIO + `assinarComToken`);
 *  - toda chamada de rede que falha vira `FalhaNoGateway` (nunca exceção do HttpClient vazando);
 *  - `interpretarAviso` autentica antes de ler e lança `AvisoNaoAutenticado` sem a credencial;
 *  - `consultar` é a fonte da verdade e é idempotente.
 */
interface GatewayDeAssinatura
{
    /** 'asaas', 'mercado_pago', 'stripe', 'pagarme' (ou 'falso', nos testes). */
    public function codigo(): string;

    /** Começa o checkout do pedido. @throws FalhaNoGateway */
    public function iniciar(PedidoDeCheckout $pedido): InicioDoCheckout;

    /**
     * Cria a assinatura com o token gerado no navegador (modo FORMULARIO).
     *
     * @throws FalhaNoGateway
     * @throws \LogicException quando o gateway só trabalha com página hospedada
     */
    public function assinarComToken(PedidoDeCheckout $pedido, string $tokenDoCartao): SituacaoNoGateway;

    /** A situação atual, lida no gateway. @throws FalhaNoGateway */
    public function consultar(string $idDaAssinatura): SituacaoNoGateway;

    /**
     * Traduz um webhook. `$cabecalhos` em minúsculas. Devolve null para evento que o Contribua não usa.
     *
     * @param array<string, string> $cabecalhos
     *
     * @throws AvisoNaoAutenticado
     */
    public function interpretarAviso(array $cabecalhos, string $corpo): ?AvisoDoGateway;

    /** Cancela no gateway (a cobrança seguinte não acontece). Cancelar o que já está cancelado não é erro. @throws FalhaNoGateway */
    public function cancelar(string $idDaAssinatura): void;
}
