<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\MercadoPago;

use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;

/**
 * Traduz a assinatura do Mercado Pago (`preapproval`,
 * https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/get-preapproval/get)
 * para a língua do Contribua.
 *
 * O `status` só diz se há cartão (`pending`/`authorized`), pausa ou cancelamento; se o mês foi pago quem diz é o
 * `summarized`: `charged_quantity` (cobranças feitas) e `semaphore` (green: tudo cobrado; yellow: tentando
 * cobrar uma fatura; red: fatura não cobrada). A primeira cobrança sai até cerca de 1 h depois da assinatura.
 */
final class TradutorMercadoPago
{
    /** @param array<string, mixed> $assinatura */
    public static function situacao(array $assinatura): SituacaoNoGateway
    {
        $id = $assinatura['id'] ?? null;
        $situacao = self::traduzir($assinatura);
        if (!\is_string($id) || $id === '' || $situacao === null) {
            throw new FalhaNoGateway('Mercado Pago devolveu uma assinatura sem id ou com situação desconhecida.');
        }
        $recorrencia = \is_array($assinatura['auto_recurring'] ?? null) ? $assinatura['auto_recurring'] : [];
        $valor = $recorrencia['transaction_amount'] ?? null;

        return new SituacaoNoGateway(
            $id,
            $situacao,
            self::pagaAte($situacao, $assinatura, $recorrencia),
            self::texto($assinatura['external_reference'] ?? null),
            self::texto($assinatura['payer_id'] ?? null),
            is_numeric($valor) ? (int) round((float) $valor * 100) : null,
        );
    }

    /**
     * Sem nenhuma cobrança feita é Aguardando, mesmo com o semáforo amarelo ou vermelho: a primeira cobrança
     * recusada não é inadimplência de quem nunca pagou. A doc escreve `canceled`; `cancelled` também é aceito,
     * porque a grafia que a API devolve não foi confirmada.
     *
     * @param array<string, mixed> $assinatura
     */
    private static function traduzir(array $assinatura): ?SituacaoDaAssinatura
    {
        $resumo = \is_array($assinatura['summarized'] ?? null) ? $assinatura['summarized'] : [];
        $pagou = (int) ($resumo['charged_quantity'] ?? 0) > 0;
        $cobrancaFalhando = \in_array($resumo['semaphore'] ?? null, ['yellow', 'red'], true);

        return match ($assinatura['status'] ?? null) {
            'pending' => SituacaoDaAssinatura::Aguardando,
            'authorized' => match (true) {
                !$pagou => SituacaoDaAssinatura::Aguardando,
                $cobrancaFalhando => SituacaoDaAssinatura::Inadimplente,
                default => SituacaoDaAssinatura::Ativa,
            },
            'paused' => $pagou ? SituacaoDaAssinatura::Inadimplente : SituacaoDaAssinatura::Aguardando,
            'canceled', 'cancelled' => SituacaoDaAssinatura::Cancelada,
            default => null,
        };
    }

    /**
     * Ativa: até a próxima cobrança (`next_payment_date`); sem ela, um período depois da última cobrança.
     * Inadimplente ou cancelada: um período depois da última cobrança feita (`summarized.last_charged_date`),
     * quando houve alguma. Aguardando: nada.
     *
     * @param array<string, mixed> $assinatura
     * @param array<string, mixed> $recorrencia
     */
    private static function pagaAte(SituacaoDaAssinatura $situacao, array $assinatura, array $recorrencia): ?\DateTimeImmutable
    {
        $proxima = self::data($assinatura['next_payment_date'] ?? null);
        $ultima = self::data($assinatura['summarized']['last_charged_date'] ?? null)
            ?? ($situacao === SituacaoDaAssinatura::Ativa ? self::data($assinatura['date_created'] ?? null) : null);
        if ($situacao === SituacaoDaAssinatura::Ativa && $proxima !== null) {
            return $proxima;
        }
        if ($situacao === SituacaoDaAssinatura::Aguardando || $ultima === null) {
            return null;
        }

        return self::somarPeriodo($ultima, $recorrencia);
    }

    /**
     * Um período da recorrência. Em meses, sem pular mês: 31/01 + 1 mês é 28/02 (ou 29), não 03/03.
     *
     * @param array<string, mixed> $recorrencia
     */
    private static function somarPeriodo(\DateTimeImmutable $data, array $recorrencia): \DateTimeImmutable
    {
        $quantos = max(1, (int) ($recorrencia['frequency'] ?? 1));
        if (($recorrencia['frequency_type'] ?? 'months') === 'days') {
            return $data->modify(sprintf('+%d days', $quantos));
        }
        $somada = $data->modify(sprintf('first day of +%d months', $quantos));
        $dia = min((int) $data->format('j'), (int) $somada->format('t'));

        return $somada->setDate((int) $somada->format('Y'), (int) $somada->format('n'), $dia);
    }

    private static function data(mixed $valor): ?\DateTimeImmutable
    {
        if (!\is_string($valor) || $valor === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($valor);
        } catch (\Exception) {
            return null;
        }
    }

    /** O `external_reference` e o `payer_id` aparecem como texto ou número nos exemplos da doc. */
    private static function texto(mixed $valor): ?string
    {
        return (\is_string($valor) && $valor !== '') || \is_int($valor) ? (string) $valor : null;
    }
}
