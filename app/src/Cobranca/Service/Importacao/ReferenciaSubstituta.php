<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Referência de deduplicação para dívida que a fonte traz SEM Nosso Número.
 * Spec: `docs/specs/cobranca-divida-sem-numero-de-boleto.md`.
 *
 * O NN é a chave de dedup da importação (índice único parcial
 * `uniq_cobranca_obrigacao_ref_competencia` sobre `caso_id + referencia_externa + competencia`).
 * 405 linhas da fonte nunca tiveram boleto emitido — são taxas mensais antigas, reais. Aceitá-las
 * sem chave duplicaria a dívida na segunda importação; descartá-las esconde R$ 17.444,66.
 *
 * A saída é o que resta do boleto quando não há boleto: o vencimento. Como `caso_id` e `competencia`
 * já compõem o índice, a referência só precisa carregar a data — e as duas fontes (Inadimplência e
 * "Relação das contas originais" dos Acordos) usam ESTA classe, para que a mesma dívida vinda das
 * duas deduplique em vez de duplicar.
 *
 * O prefixo é o que garante que uma referência sintética nunca colida com um boleto de verdade:
 * NN real é sempre dígito puro.
 */
final class ReferenciaSubstituta
{
    public const PREFIXO = 'SNN:';

    public static function para(\DateTimeImmutable $vencimento): string
    {
        return self::PREFIXO . $vencimento->format('Y-m-d');
    }

    /** Distingue dívida sem boleto de boleto de verdade, na tela e no relatório da importação. */
    public static function ehSubstituta(?string $referencia): bool
    {
        return $referencia !== null && str_starts_with($referencia, self::PREFIXO);
    }
}
