<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Leitura agregada do Dashboard do escritório (Etapa 9, SPEC §20). Todos os valores monetários em
 * CENTAVOS inteiros (formatação só no Twig via `|centavos`); contagens em int. Tudo é DERIVADO dos
 * serviços de saldo/honorários/alertas caso a caso (invariável 20, §18/§19) — este DTO só carrega o
 * resultado pronto para a tela, sem regra de negócio. `taxaRecuperacaoBasisPoints` é 1/100 de %
 * (5000 = 50,00%), calculado em aritmética inteira.
 */
final class DashboardCobrancaOutput
{
    public function __construct(
        // Visão financeira
        public readonly int $saldoEmAberto,
        public readonly int $saldoVencido,
        public readonly int $valorRecuperadoNoPeriodo,
        public readonly int $honorariosProjetados,
        public readonly int $honorariosRealizadosNoPeriodo,
        // Visão operacional (contagens de casos)
        public readonly int $pagamentosAVerificar,
        public readonly int $proximasAcoesAtrasadas,
        public readonly int $parcelasAcordoVencidas,
        public readonly int $casosJudicializados,
        // Resultado
        public readonly int $valorTotalRecuperado,
        public readonly int $valorEmAberto,
        public readonly int $taxaRecuperacaoBasisPoints,
        public readonly int $objetosInadimplentes,
        public readonly int $casosAtivos,
        // Contexto/echo dos filtros
        public readonly int $totalCasos,
        public readonly \DateTimeImmutable $periodoInicio,
        public readonly \DateTimeImmutable $periodoFim,
        public readonly ?int $carteiraId,
    ) {
    }
}
