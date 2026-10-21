<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

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
 *
 * Todo adaptador recebe a tag `contribua.gateway` sozinho (atributo abaixo) e é escolhido pelo `codigo()`
 * que o Master configurou: o adaptador não precisa de registro em `services.yaml`. As credenciais vêm do
 * ambiente, com o prefixo `CONTRIBUA_<GATEWAY>_` (ex.: `CONTRIBUA_ASAAS_API_KEY`), lidas no construtor
 * do próprio adaptador com `#[Autowire(env: ...)]`.
 */
#[AutoconfigureTag(GatewayDeAssinatura::TAG)]
interface GatewayDeAssinatura
{
    public const TAG = 'contribua.gateway';

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
     * Traduz um webhook. `$cabecalhos` em minúsculas; os parâmetros da URL chegam como `x-query-<nome>`.
     * Devolve null para evento que o Contribua não usa.
     *
     * @param array<string, string> $cabecalhos
     *
     * @throws AvisoNaoAutenticado
     * @throws FalhaNoGateway      quando precisou consultar o gateway e ele não respondeu (o webhook responde 503)
     */
    public function interpretarAviso(array $cabecalhos, string $corpo): ?AvisoDoGateway;

    /** Cancela no gateway (a cobrança seguinte não acontece). Cancelar o que já está cancelado não é erro. @throws FalhaNoGateway */
    public function cancelar(string $idDaAssinatura): void;
}
