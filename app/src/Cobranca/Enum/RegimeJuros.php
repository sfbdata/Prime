<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Regime de capitalização dos juros de mora (spec "encargos configuráveis em cascata" §3).
 *
 * O default do módulo — e o único regime PROVADO contra dados reais (4.413 linhas TOPLIFE,
 * Apêndice A da spec) — é o SIMPLES: juros pró-rata diária sobre o principal puro, mês = 30 dias.
 * O composto existe porque o produto é SaaS e cada escritório configura o que quiser, mas NÃO
 * está validado contra extrato da contabilidade.
 */
enum RegimeJuros: string
{
    /** Juros simples: incidem sempre sobre o principal, pró-rata diária (mês = 30 dias). */
    case Simples = 'simples';

    /** Juros compostos: capitalizam a cada mês inteiro de atraso; o resto de dias é pró-rata. */
    case Composto = 'composto';

    public function label(): string
    {
        return match ($this) {
            self::Simples => 'Juros simples',
            self::Composto => 'Juros compostos',
        };
    }
}
