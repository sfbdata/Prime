<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\Stripe;

use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;

/**
 * Traduz os objetos do Stripe (versão `ClienteStripe::VERSAO_DA_API`) para a língua do Contribua.
 *
 * Desde a 2025-03-31.basil o período pago mora no item (`items.data[].current_period_end`), não mais na
 * assinatura, e a fatura aponta a assinatura em `parent.subscription_details` (não mais em `subscription`).
 */
final class TradutorStripe
{
    /**
     * https://docs.stripe.com/api/subscriptions/object (status). `paused` só acontece quando um período de
     * teste termina sem cartão; o Contribua não dá teste, e se acontecer é cobrança que não veio.
     */
    private const SITUACOES = [
        'incomplete' => SituacaoDaAssinatura::Aguardando,
        'trialing' => SituacaoDaAssinatura::Ativa,
        'active' => SituacaoDaAssinatura::Ativa,
        'past_due' => SituacaoDaAssinatura::Inadimplente,
        'unpaid' => SituacaoDaAssinatura::Inadimplente,
        'paused' => SituacaoDaAssinatura::Inadimplente,
        'canceled' => SituacaoDaAssinatura::Cancelada,
        'incomplete_expired' => SituacaoDaAssinatura::Cancelada,
    ];

    private const EVENTOS_DA_ASSINATURA = [
        'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted',
        'customer.subscription.paused', 'customer.subscription.resumed',
    ];

    private const EVENTOS_DA_FATURA = ['invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required'];

    /** @param array<string, mixed> $assinatura objeto Subscription, de preferência com `latest_invoice` expandido */
    public static function situacao(array $assinatura): SituacaoNoGateway
    {
        $id = $assinatura['id'] ?? null;
        $situacao = self::SITUACOES[$assinatura['status'] ?? ''] ?? null;
        if (!\is_string($id) || $situacao === null) {
            throw new FalhaNoGateway('Stripe devolveu uma assinatura sem id ou com situação desconhecida.');
        }
        $item = $assinatura['items']['data'][0] ?? [];
        $metadados = \is_array($assinatura['metadata'] ?? null) ? $assinatura['metadata'] : [];
        $valor = $item['price']['unit_amount'] ?? null;

        return new SituacaoNoGateway(
            $id,
            $situacao,
            self::pagaAte($situacao, $item, $assinatura['latest_invoice'] ?? null),
            \is_string($metadados['referencia'] ?? null) ? $metadados['referencia'] : null,
            \is_string($assinatura['customer'] ?? null) ? $assinatura['customer'] : null,
            \is_int($valor) ? $valor : null,
        );
    }

    /**
     * O que reconsultar a partir de um evento: assinatura, referência e cliente. Null para evento que o
     * Contribua não usa.
     *
     * @param array<string, mixed> $objeto `data.object` do evento
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}|null
     */
    public static function alvoDoAviso(string $tipo, array $objeto): ?array
    {
        if (\in_array($tipo, self::EVENTOS_DA_ASSINATURA, true)) {
            return [self::texto($objeto['id'] ?? null), self::texto($objeto['metadata']['referencia'] ?? null), self::texto($objeto['customer'] ?? null)];
        }
        if (\in_array($tipo, self::EVENTOS_DA_FATURA, true)) {
            $origem = $objeto['parent']['subscription_details'] ?? [];

            return [self::texto($origem['subscription'] ?? null), self::texto($origem['metadata']['referencia'] ?? null), self::texto($objeto['customer'] ?? null)];
        }

        return null;
    }

    /**
     * Até quando o mês está pago: o fim do período vigente quando ativa; o começo dele quando inadimplente
     * (o período vigente é o que não foi pago); o fim do período quando cancelada depois de paga; nada
     * enquanto não houve pagamento.
     *
     * @param array<string, mixed> $item
     */
    private static function pagaAte(SituacaoDaAssinatura $situacao, array $item, mixed $ultimaFatura): ?\DateTimeImmutable
    {
        $campo = match (true) {
            $situacao === SituacaoDaAssinatura::Ativa => 'current_period_end',
            $situacao === SituacaoDaAssinatura::Inadimplente => 'current_period_start',
            $situacao === SituacaoDaAssinatura::Cancelada && ($ultimaFatura['status'] ?? null) === 'paid' => 'current_period_end',
            default => null,
        };
        $carimbo = $campo === null ? null : ($item[$campo] ?? null);

        return \is_int($carimbo) ? (new \DateTimeImmutable('@' . $carimbo))->setTimezone(new \DateTimeZone('America/Sao_Paulo')) : null;
    }

    private static function texto(mixed $valor): ?string
    {
        return \is_string($valor) && $valor !== '' ? $valor : null;
    }
}
