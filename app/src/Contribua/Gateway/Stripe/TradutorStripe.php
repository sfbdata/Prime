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
     * https://docs.stripe.com/api/subscriptions/object (status). `trialing` é Aguardando: o Contribua não dá
     * período de teste, e um teste criado no painel não é pagamento. `paused` só acontece quando um teste
     * termina sem cartão.
     */
    private const SITUACOES = [
        'incomplete' => SituacaoDaAssinatura::Aguardando,
        'trialing' => SituacaoDaAssinatura::Aguardando,
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

    /**
     * @param array<string, mixed>      $assinatura      objeto Subscription
     * @param array<string, mixed>|null $ultimaFaturaPaga a última Invoice paga da assinatura, quando a situação
     *                                                     pede (`precisaDaUltimaFaturaPaga`)
     */
    public static function situacao(array $assinatura, ?array $ultimaFaturaPaga = null, ?string $acaoNoNavegador = null): SituacaoNoGateway
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
            self::pagaAte($situacao, $item, $ultimaFaturaPaga),
            \is_string($metadados['referencia'] ?? null) ? $metadados['referencia'] : null,
            \is_string($assinatura['customer'] ?? null) ? $assinatura['customer'] : null,
            \is_int($valor) ? $valor : null,
            $acaoNoNavegador,
        );
    }

    /**
     * Inadimplente ou cancelada: o mês pago vem da última fatura paga, não do período vigente.
     *
     * @param array<string, mixed> $assinatura
     */
    public static function precisaDaUltimaFaturaPaga(array $assinatura): bool
    {
        $situacao = self::SITUACOES[$assinatura['status'] ?? ''] ?? null;

        return $situacao === SituacaoDaAssinatura::Inadimplente || $situacao === SituacaoDaAssinatura::Cancelada;
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
     * Até quando o mês está pago. Ativa: o fim do período vigente. Inadimplente ou cancelada: o fim do período
     * da última fatura paga (`lines.data[].period.end`; o `period_end` da fatura de assinatura é o do período
     * anterior). O período vigente de uma inadimplente avança todo mês sem pagamento, então não serve. Nada
     * enquanto não houve pagamento.
     *
     * @param array<string, mixed>      $item
     * @param array<string, mixed>|null $ultimaFaturaPaga
     */
    private static function pagaAte(SituacaoDaAssinatura $situacao, array $item, ?array $ultimaFaturaPaga): ?\DateTimeImmutable
    {
        $carimbo = match ($situacao) {
            SituacaoDaAssinatura::Ativa => $item['current_period_end'] ?? null,
            SituacaoDaAssinatura::Inadimplente, SituacaoDaAssinatura::Cancelada => self::fimDoPeriodoPago($ultimaFaturaPaga),
            SituacaoDaAssinatura::Aguardando => null,
        };

        return \is_int($carimbo) ? (new \DateTimeImmutable('@' . $carimbo))->setTimezone(new \DateTimeZone('America/Sao_Paulo')) : null;
    }

    /** @param array<string, mixed>|null $fatura */
    private static function fimDoPeriodoPago(?array $fatura): ?int
    {
        if (($fatura['status'] ?? null) !== 'paid') {
            return null;
        }
        $fins = array_filter(array_map(static fn (mixed $linha): mixed => \is_array($linha) ? ($linha['period']['end'] ?? null) : null, $fatura['lines']['data'] ?? []), 'is_int');

        return $fins === [] ? null : max($fins);
    }

    private static function texto(mixed $valor): ?string
    {
        return \is_string($valor) && $valor !== '' ? $valor : null;
    }
}
