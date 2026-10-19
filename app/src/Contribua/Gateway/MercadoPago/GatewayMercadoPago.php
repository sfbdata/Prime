<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\MercadoPago;

use App\Contribua\Gateway\AvisoDoGateway;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Assinatura mensal de espaço pelo Mercado Pago ("assinatura sem plano associado, com pagamento autorizado"),
 * com o cartão tokenizado no navegador (`public/js/contribua-mercado-pago.js`, Card Payment Brick): o
 * servidor só recebe o `card_token_id`, de uso único e válido por até 7 dias.
 *
 * A referência do pedido vai em `external_reference` e volta na consulta.
 *
 * Documentação: https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-no-associated-plan/authorized-payments
 */
final class GatewayMercadoPago implements GatewayDeAssinatura
{
    private const AVISO_DA_ASSINATURA = 'subscription_preapproval';
    private const AVISO_DA_FATURA = 'subscription_authorized_payment';

    public function __construct(
        private readonly ClienteMercadoPago $mercadoPago,
        private readonly AssinaturaDoAvisoMercadoPago $assinaturaDoAviso,
        #[Autowire('%env(string:default::CONTRIBUA_MERCADO_PAGO_PUBLIC_KEY)%')]
        private readonly string $chavePublica,
    ) {
    }

    public function codigo(): string
    {
        return 'mercado_pago';
    }

    /**
     * Confere a credencial antes de abrir o formulário (`GET /users/me`): com o Mercado Pago fora do ar ou o
     * token errado, o escritório não chega a digitar o cartão.
     */
    public function iniciar(PedidoDeCheckout $pedido): InicioDoCheckout
    {
        if ($this->chavePublica === '') {
            throw new FalhaNoGateway('CONTRIBUA_MERCADO_PAGO_PUBLIC_KEY não configurada.');
        }
        $this->mercadoPago->ler('/users/me');

        return InicioDoCheckout::formulario($this->chavePublica);
    }

    public function assinarComToken(PedidoDeCheckout $pedido, string $tokenDoCartao): SituacaoNoGateway
    {
        if (preg_match('/^[A-Za-z0-9]{16,64}$/', $tokenDoCartao) !== 1) {
            throw new \InvalidArgumentException('O Mercado Pago espera o token do cartão gerado no navegador.');
        }

        return TradutorMercadoPago::situacao($this->mercadoPago->enviar(
            'POST',
            '/preapproval',
            self::camposDaAssinatura($pedido, $tokenDoCartao),
            'assinatura-' . $pedido->referencia . '-' . substr(hash('sha256', $tokenDoCartao), 0, 16),
        ));
    }

    public function consultar(string $idDaAssinatura): SituacaoNoGateway
    {
        return TradutorMercadoPago::situacao($this->mercadoPago->ler('/preapproval/' . rawurlencode($idDaAssinatura)));
    }

    /**
     * O `data.id` assinado é o da query da URL, que o controller repassa em `x-query-data.id`; sem ele, o do
     * corpo (o mesmo recurso). Aviso de assinatura: o `data.id` é a assinatura. Aviso de fatura: o `data.id` é a fatura, e a
     * assinatura dela vem de `GET /authorized_payments/{id}` (pode lançar `FalhaNoGateway`; o Mercado Pago
     * reenvia o aviso). Pagamento avulso e o resto: null.
     */
    public function interpretarAviso(array $cabecalhos, string $corpo): ?AvisoDoGateway
    {
        $aviso = json_decode($corpo, true);
        $idDoRecurso = self::texto($cabecalhos['x-query-data.id'] ?? null) ?? (\is_array($aviso) ? self::texto($aviso['data']['id'] ?? null) : null);
        $this->assinaturaDoAviso->exigirAutentico($cabecalhos['x-signature'] ?? '', $cabecalhos['x-request-id'] ?? '', $idDoRecurso ?? '');
        $idDoEvento = \is_array($aviso) ? self::texto($aviso['id'] ?? null) : null;
        $tipo = \is_array($aviso) ? self::texto($aviso['type'] ?? $aviso['topic'] ?? null) : null;
        if ($idDoEvento === null || $idDoRecurso === null || !\in_array($tipo, [self::AVISO_DA_ASSINATURA, self::AVISO_DA_FATURA], true)) {
            return null;
        }
        $idDaAssinatura = $tipo === self::AVISO_DA_ASSINATURA ? $idDoRecurso : $this->assinaturaDaFatura($idDoRecurso);

        return $idDaAssinatura === null ? null : new AvisoDoGateway($idDoEvento, $tipo, $idDaAssinatura);
    }

    /** `PUT /preapproval/{id}` com `status=canceled` (irreversível). Já cancelada: a consulta confirma e vira sucesso. */
    public function cancelar(string $idDaAssinatura): void
    {
        try {
            $this->mercadoPago->enviar('PUT', '/preapproval/' . rawurlencode($idDaAssinatura), ['status' => 'canceled']);
        } catch (FalhaNoGateway $falha) {
            if ($falha->getCode() < 400 || $falha->getCode() >= 500 || $this->consultar($idDaAssinatura)->situacao !== SituacaoDaAssinatura::Cancelada) {
                throw $falha;
            }
        }
    }

    private function assinaturaDaFatura(string $idDaFatura): ?string
    {
        return self::texto($this->mercadoPago->ler('/authorized_payments/' . rawurlencode($idDaFatura))['preapproval_id'] ?? null);
    }

    /**
     * Sem `start_date`/`end_date`: a doc diz que o início só vale com o fim, e a assinatura de espaço não tem
     * fim. O valor vai em reais (o Mercado Pago não usa centavos).
     *
     * @return array<string, mixed>
     */
    private static function camposDaAssinatura(PedidoDeCheckout $pedido, string $tokenDoCartao): array
    {
        return [
            'reason' => 'BlueJus: espaço ' . $pedido->planoNome,
            'external_reference' => $pedido->referencia,
            'payer_email' => $pedido->pagador->email,
            'card_token_id' => $tokenDoCartao,
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => round($pedido->valorCentavos / 100, 2),
                'currency_id' => 'BRL',
            ],
            'back_url' => $pedido->urlDeRetorno,
            'status' => 'authorized',
        ];
    }

    private static function texto(mixed $valor): ?string
    {
        return (\is_string($valor) && $valor !== '') || \is_int($valor) ? (string) $valor : null;
    }
}
