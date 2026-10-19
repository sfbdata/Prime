<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\Pagarme;

use App\Contribua\Gateway\FalhaNoGateway;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Gateway\SituacaoNoGateway;

/**
 * Traduz a assinatura do Pagar.me (https://docs.pagar.me/reference/assinaturas-1) e as faturas dela
 * (https://docs.pagar.me/reference/faturas-1) para a língua do Contribua.
 *
 * A assinatura só diz `active`, `canceled` ou `future` (e, nos módulos oficiais, `failed` e `chargedback`):
 * se o mês foi pago quem diz são as faturas de cada ciclo (`paid`, `failed`, `pending`, `scheduled`,
 * `canceled`). Sem fatura paga ainda, é Aguardando (a primeira cobrança recusada não é inadimplência de quem
 * nunca pagou); já pagou e a última fatura falhou, Inadimplente.
 */
final class TradutorPagarme
{
    /**
     * @param array<string, mixed>       $assinatura
     * @param list<array<string, mixed>> $faturas    da assinatura, em qualquer ordem
     */
    public static function situacao(array $assinatura, array $faturas): SituacaoNoGateway
    {
        $id = $assinatura['id'] ?? null;
        $faturas = self::maisRecentesPrimeiro($faturas);
        $situacao = self::traduzir((string) ($assinatura['status'] ?? ''), $faturas);
        if (!\is_string($id) || $id === '' || $situacao === null) {
            throw new FalhaNoGateway('Pagar.me devolveu uma assinatura sem id ou com situação desconhecida.');
        }
        $metadados = \is_array($assinatura['metadata'] ?? null) ? $assinatura['metadata'] : [];
        $preco = $assinatura['items'][0]['pricing_scheme']['price'] ?? null;

        return new SituacaoNoGateway(
            $id,
            $situacao,
            self::pagaAte($situacao, $assinatura, $faturas),
            self::texto($metadados['referencia'] ?? $assinatura['code'] ?? null),
            self::texto($assinatura['customer']['id'] ?? null),
            \is_int($preco) ? $preco : null,
        );
    }

    /** @param list<array<string, mixed>> $faturas */
    private static function traduzir(string $status, array $faturas): ?SituacaoDaAssinatura
    {
        $paga = self::primeiraPaga($faturas) !== null;

        return match ($status) {
            'future' => SituacaoDaAssinatura::Aguardando,
            'active' => match (true) {
                !$paga => SituacaoDaAssinatura::Aguardando,
                ($faturas[0]['status'] ?? null) === 'failed' => SituacaoDaAssinatura::Inadimplente,
                default => SituacaoDaAssinatura::Ativa,
            },
            'canceled', 'failed', 'chargedback' => SituacaoDaAssinatura::Cancelada,
            default => null,
        };
    }

    /**
     * Até o fim do ciclo da última fatura paga; sem o ciclo na fatura, um mês depois da cobrança dela. Estorno
     * contestado (`chargedback`): nada, o dinheiro voltou.
     *
     * @param array<string, mixed>       $assinatura
     * @param list<array<string, mixed>> $faturas
     */
    private static function pagaAte(SituacaoDaAssinatura $situacao, array $assinatura, array $faturas): ?\DateTimeImmutable
    {
        $paga = self::primeiraPaga($faturas);
        if ($situacao === SituacaoDaAssinatura::Aguardando || $paga === null || ($assinatura['status'] ?? null) === 'chargedback') {
            return null;
        }
        $cobradaEm = self::data($paga['billing_at'] ?? $paga['created_at'] ?? null);

        return self::data($paga['cycle']['end_at'] ?? null) ?? ($cobradaEm === null ? null : self::umMesDepois($cobradaEm));
    }

    /** 31/01 vai a 28/02 (ou 29), sem pular para março. */
    private static function umMesDepois(\DateTimeImmutable $data): \DateTimeImmutable
    {
        $mes = $data->modify('first day of +1 month');

        return $mes->setDate((int) $mes->format('Y'), (int) $mes->format('n'), min((int) $data->format('j'), (int) $mes->format('t')));
    }

    /**
     * @param list<array<string, mixed>> $faturas
     *
     * @return array<string, mixed>|null
     */
    private static function primeiraPaga(array $faturas): ?array
    {
        foreach ($faturas as $fatura) {
            if (($fatura['status'] ?? null) === 'paid') {
                return $fatura;
            }
        }

        return null;
    }

    /**
     * Pelo começo do ciclo (ou pela criação, sem ciclo): a ordem da listagem não é documentada.
     *
     * @param list<array<string, mixed>> $faturas
     *
     * @return list<array<string, mixed>>
     */
    private static function maisRecentesPrimeiro(array $faturas): array
    {
        $quando = static fn (array $f): string => (string) ($f['cycle']['start_at'] ?? $f['created_at'] ?? '');
        usort($faturas, static fn (array $a, array $b): int => strcmp($quando($b), $quando($a)));

        return $faturas;
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

    private static function texto(mixed $valor): ?string
    {
        return \is_string($valor) && $valor !== '' ? $valor : null;
    }
}
