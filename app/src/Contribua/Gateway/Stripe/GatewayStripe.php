<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\Stripe;

use App\Contribua\Gateway\AvisoDoGateway;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Assinatura mensal de espaço pelo Stripe, com o cartão tokenizado no navegador
 * (`public/js/contribua-stripe.js`, Payment Element): o servidor só recebe o id do PaymentMethod (`pm_...`).
 *
 * Fluxo: o produto do plano existe no Stripe (criado na primeira vez, com id fixo), o cliente é criado já
 * com o cartão como padrão das faturas, e a assinatura é criada com o preço do pedido em BRL. A referência
 * do pedido vai em `metadata[referencia]` da assinatura e do cliente, e volta na consulta e nos avisos.
 *
 * Documentação: https://docs.stripe.com/billing/subscriptions/build-subscriptions e
 * https://docs.stripe.com/api/subscriptions.
 */
final class GatewayStripe implements GatewayDeAssinatura
{
    public function __construct(
        private readonly ClienteStripe $stripe,
        private readonly AssinaturaDoAvisoStripe $assinaturaDoAviso,
        #[Autowire('%env(string:default::CONTRIBUA_STRIPE_PUBLIC_KEY)%')]
        private readonly string $chavePublica,
    ) {
    }

    public function codigo(): string
    {
        return 'stripe';
    }

    /** Garante o produto do plano no Stripe e entrega a chave pública ao formulário do navegador. */
    public function iniciar(PedidoDeCheckout $pedido): InicioDoCheckout
    {
        if ($this->chavePublica === '') {
            throw new FalhaNoGateway('CONTRIBUA_STRIPE_PUBLIC_KEY não configurada.');
        }
        $this->garantirProduto($pedido);

        return InicioDoCheckout::formulario($this->chavePublica);
    }

    public function assinarComToken(PedidoDeCheckout $pedido, string $tokenDoCartao): SituacaoNoGateway
    {
        if (preg_match('/^pm_\w+$/', $tokenDoCartao) !== 1) {
            throw new \InvalidArgumentException('O Stripe espera o id de um PaymentMethod (pm_...).');
        }
        $produto = $this->garantirProduto($pedido);
        $cliente = $this->criarCliente($pedido, $tokenDoCartao);
        $assinatura = $this->stripe->enviar(
            '/v1/subscriptions',
            self::camposDaAssinatura($pedido, $produto, $cliente, $tokenDoCartao),
            'assinatura-' . $pedido->referencia . '-' . self::marca($tokenDoCartao),
        );

        return TradutorStripe::situacao($assinatura);
    }

    public function consultar(string $idDaAssinatura): SituacaoNoGateway
    {
        return TradutorStripe::situacao(
            $this->stripe->ler('/v1/subscriptions/' . rawurlencode($idDaAssinatura), ['expand' => ['latest_invoice']]),
        );
    }

    public function interpretarAviso(array $cabecalhos, string $corpo): ?AvisoDoGateway
    {
        $this->assinaturaDoAviso->exigirAutentico($cabecalhos['stripe-signature'] ?? '', $corpo);
        $evento = json_decode($corpo, true);
        if (!\is_array($evento) || !\is_string($evento['id'] ?? null) || !\is_string($evento['type'] ?? null) || !\is_array($evento['data']['object'] ?? null)) {
            return null;
        }
        $alvo = TradutorStripe::alvoDoAviso($evento['type'], $evento['data']['object']);
        if ($alvo === null || $alvo === [null, null, null]) {
            return null;
        }

        return new AvisoDoGateway($evento['id'], $evento['type'], $alvo[0], $alvo[1], $alvo[2]);
    }

    /**
     * Cancela na hora, sem reembolso proporcional: o espaço vale até o fim do mês pago (`pagaAte`). Cancelar
     * o que já terminou responde 4xx no Stripe; a consulta confirma e o cancelamento vira sucesso.
     */
    public function cancelar(string $idDaAssinatura): void
    {
        try {
            $this->stripe->apagar('/v1/subscriptions/' . rawurlencode($idDaAssinatura));
        } catch (FalhaNoGateway $falha) {
            if ($falha->getCode() < 400 || $falha->getCode() >= 500 || !$this->jaEncerrada($idDaAssinatura)) {
                throw $falha;
            }
        }
    }

    private function jaEncerrada(string $idDaAssinatura): bool
    {
        return $this->consultar($idDaAssinatura)->situacao === SituacaoDaAssinatura::Cancelada;
    }

    /** Um produto por plano, com id fixo (`bluejus_espaco_<plano>`): o preço vai em cada assinatura. */
    private function garantirProduto(PedidoDeCheckout $pedido): string
    {
        $id = 'bluejus_espaco_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($pedido->planoCodigo));
        try {
            $this->stripe->ler('/v1/products/' . $id);
        } catch (FalhaNoGateway $falha) {
            if ($falha->getCode() !== 404) {
                throw $falha;
            }
            $this->stripe->enviar('/v1/products', ['id' => $id, 'name' => 'BlueJus: espaço ' . $pedido->planoNome, 'metadata' => ['plano' => $pedido->planoCodigo]], 'produto-' . $id);
        }

        return $id;
    }

    private function criarCliente(PedidoDeCheckout $pedido, string $tokenDoCartao): string
    {
        $cliente = $this->stripe->enviar('/v1/customers', [
            'name' => $pedido->pagador->nome,
            'email' => $pedido->pagador->email,
            'payment_method' => $tokenDoCartao,
            'invoice_settings' => ['default_payment_method' => $tokenDoCartao],
            'preferred_locales' => ['pt-BR'],
            'metadata' => ['referencia' => $pedido->referencia, 'escritorio' => (string) $pedido->tenantId],
        ], 'cliente-' . $pedido->referencia . '-' . self::marca($tokenDoCartao));

        return \is_string($cliente['id'] ?? null) ? $cliente['id'] : throw new FalhaNoGateway('Stripe criou o cliente sem devolver o id.');
    }

    /**
     * `allow_incomplete` (o padrão do Stripe): a primeira fatura é cobrada na criação; recusada ou pedindo
     * autenticação, a assinatura nasce `incomplete` (Aguardando) e expira em 23 h se ninguém pagar.
     *
     * @return array<string, mixed>
     */
    private static function camposDaAssinatura(PedidoDeCheckout $pedido, string $produto, string $cliente, string $tokenDoCartao): array
    {
        return [
            'customer' => $cliente,
            'items' => [['price_data' => [
                'currency' => 'brl',
                'product' => $produto,
                'unit_amount' => $pedido->valorCentavos,
                'recurring' => ['interval' => 'month'],
            ]]],
            'default_payment_method' => $tokenDoCartao,
            'payment_behavior' => 'allow_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'metadata' => ['referencia' => $pedido->referencia, 'escritorio' => (string) $pedido->tenantId, 'plano' => $pedido->planoCodigo],
            'expand' => ['latest_invoice'],
        ];
    }

    /** Parte da chave de idempotência: outro cartão no mesmo pedido é outro pedido ao Stripe. */
    private static function marca(string $tokenDoCartao): string
    {
        return substr(hash('sha256', $tokenDoCartao), 0, 16);
    }
}
