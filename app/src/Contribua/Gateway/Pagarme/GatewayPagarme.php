<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\Pagarme;

use App\Contribua\Gateway\AvisoDoGateway;
use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\GatewayDeAssinatura;
use App\Contribua\Gateway\InicioDoCheckout;
use App\Contribua\Gateway\PedidoDeCheckout;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Assinatura mensal de espaço pelo Pagar.me (API v5, "assinatura avulsa", sem plano), com o cartão
 * tokenizado no navegador (`public/js/contribua-pagarme.js`, `POST /tokens?appId=<chave pública>`): o
 * servidor só recebe o `token_...`, de uso único e válido por 60 segundos.
 *
 * A referência do pedido vai em `code` e em `metadata[referencia]` e volta na consulta.
 *
 * Documentação: https://docs.pagar.me/reference/assinaturas-1. A página "Criar assinatura avulsa" traz o
 * esquema da assinatura de plano por engano; o corpo segue o objeto `subscription`, os exemplos de resposta
 * e o SDK PHP oficial (`CreateSubscriptionRequest`).
 */
final class GatewayPagarme implements GatewayDeAssinatura
{
    /** Faturas lidas na consulta: as dos últimos 75 dias bastam para saber o mês vigente e o anterior. */
    private const JANELA_DAS_FATURAS = '-75 days';

    public function __construct(
        private readonly ClientePagarme $pagarme,
        private readonly AssinaturaDoAvisoPagarme $assinaturaDoAviso,
        private readonly ClockInterface $relogio,
        #[Autowire('%env(string:default::CONTRIBUA_PAGARME_PUBLIC_KEY)%')]
        private readonly string $chavePublica,
    ) {
    }

    public function codigo(): string
    {
        return 'pagarme';
    }

    /** Confere a credencial (`GET /customers` do documento) antes de abrir o formulário. */
    public function iniciar(PedidoDeCheckout $pedido): InicioDoCheckout
    {
        if ($this->chavePublica === '') {
            throw new FalhaNoGateway('CONTRIBUA_PAGARME_PUBLIC_KEY não configurada.');
        }
        $this->pagarme->ler('/customers', ['document' => $pedido->pagador->documento, 'size' => 1]);

        return InicioDoCheckout::formulario($this->chavePublica);
    }

    public function assinarComToken(PedidoDeCheckout $pedido, string $tokenDoCartao): SituacaoNoGateway
    {
        if (preg_match('/^token_\w+$/', $tokenDoCartao) !== 1) {
            throw new \InvalidArgumentException('O Pagar.me espera o token do cartão gerado no navegador (token_...).');
        }
        $assinatura = $this->pagarme->enviar('POST', '/subscriptions', self::camposDaAssinatura($pedido, $tokenDoCartao));

        return TradutorPagarme::situacao($assinatura, $this->faturasDaRecemCriada((string) ($assinatura['id'] ?? '')));
    }

    /**
     * A assinatura já existe (e talvez já foi cobrada): se a leitura das faturas falhar, ela volta como
     * Aguardando com o id, em vez de uma falha que faria o escritório tentar de novo e assinar duas vezes. A
     * reconsulta e o aviso acertam a situação depois.
     *
     * @return list<array<string, mixed>>
     */
    private function faturasDaRecemCriada(string $idDaAssinatura): array
    {
        try {
            return $this->faturasDe($idDaAssinatura);
        } catch (FalhaNoGateway) {
            return [];
        }
    }

    public function consultar(string $idDaAssinatura): SituacaoNoGateway
    {
        $assinatura = $this->pagarme->ler('/subscriptions/' . rawurlencode($idDaAssinatura));

        return TradutorPagarme::situacao($assinatura, $this->faturasDe($idDaAssinatura));
    }

    /**
     * `subscription.*`: o `data` é a assinatura. `invoice.*`: a fatura, com a assinatura dentro.
     * `charge.*`: a cobrança, com a assinatura em `data.invoice`. O resto: null.
     */
    public function interpretarAviso(array $cabecalhos, string $corpo): ?AvisoDoGateway
    {
        $this->assinaturaDoAviso->exigirAutentico($cabecalhos['x-webhook-asymmetric-signature'] ?? '', $corpo);
        $aviso = json_decode($corpo, true);
        $tipo = \is_array($aviso) ? self::texto($aviso['type'] ?? null) : null;
        $idDoEvento = \is_array($aviso) ? self::texto($aviso['id'] ?? null) : null;
        $dados = \is_array($aviso) && \is_array($aviso['data'] ?? null) ? $aviso['data'] : [];
        $alvo = $tipo === null ? null : self::alvoDoAviso($tipo, $dados);
        if ($idDoEvento === null || $alvo === null || $alvo === [null, null, null]) {
            return null;
        }

        return new AvisoDoGateway($idDoEvento, (string) $tipo, $alvo[0], $alvo[1], $alvo[2]);
    }

    /** `DELETE /subscriptions/{id}`, cancelando as faturas pendentes. Já cancelada: a consulta confirma e vira sucesso. */
    public function cancelar(string $idDaAssinatura): void
    {
        try {
            $this->pagarme->enviar('DELETE', '/subscriptions/' . rawurlencode($idDaAssinatura), ['cancel_pending_invoices' => true]);
        } catch (FalhaNoGateway $falha) {
            if ($falha->getCode() < 400 || $falha->getCode() >= 500 || $this->consultar($idDaAssinatura)->situacao !== SituacaoDaAssinatura::Cancelada) {
                throw $falha;
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function faturasDe(string $idDaAssinatura): array
    {
        $desde = $this->relogio->now()->modify(self::JANELA_DAS_FATURAS)->format('Y-m-d\TH:i:s\Z');
        $lista = $this->pagarme->ler('/invoices', ['subscription_id' => $idDaAssinatura, 'created_since' => $desde, 'size' => 30]);

        return array_values(array_filter(\is_array($lista['data'] ?? null) ? $lista['data'] : [], 'is_array'));
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}|null
     */
    private static function alvoDoAviso(string $tipo, array $dados): ?array
    {
        $cliente = self::texto($dados['customer']['id'] ?? null);

        return match (true) {
            str_starts_with($tipo, 'subscription.') => [self::texto($dados['id'] ?? null), self::texto($dados['metadata']['referencia'] ?? $dados['code'] ?? null), $cliente],
            str_starts_with($tipo, 'invoice.') => [self::texto($dados['subscription']['id'] ?? $dados['subscription_id'] ?? null), self::texto($dados['subscription']['metadata']['referencia'] ?? null), $cliente],
            str_starts_with($tipo, 'charge.') => [self::texto($dados['invoice']['subscription_id'] ?? $dados['invoice']['subscriptionId'] ?? null), null, $cliente],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private static function camposDaAssinatura(PedidoDeCheckout $pedido, string $tokenDoCartao): array
    {
        $empresa = \strlen($pedido->pagador->documento) === 14;

        return [
            'code' => $pedido->referencia,
            'payment_method' => 'credit_card',
            'currency' => 'BRL',
            'interval' => 'month',
            'interval_count' => 1,
            'billing_type' => 'prepaid',
            'installments' => 1,
            'card_token' => $tokenDoCartao,
            'customer' => [
                'name' => mb_substr($pedido->pagador->nome, 0, 64),
                'email' => $pedido->pagador->email,
                'document' => $pedido->pagador->documento,
                'document_type' => $empresa ? 'CNPJ' : 'CPF',
                'type' => $empresa ? 'company' : 'individual',
            ],
            'items' => [[
                'description' => 'BlueJus: espaço ' . $pedido->planoNome,
                'quantity' => 1,
                'pricing_scheme' => ['scheme_type' => 'unit', 'price' => $pedido->valorCentavos],
            ]],
            'metadata' => ['referencia' => $pedido->referencia, 'escritorio' => (string) $pedido->tenantId, 'plano' => $pedido->planoCodigo],
        ];
    }

    private static function texto(mixed $valor): ?string
    {
        return \is_string($valor) && $valor !== '' ? $valor : null;
    }
}
