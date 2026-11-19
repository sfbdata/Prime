<?php

declare(strict_types=1);

namespace App\Inteligencia\Service\Saldo;

use App\Entity\Auth\User;

/**
 * Como se mede o uso gratuito de I.A. de uma pessoa (spec `ia-liberada-por-indicacao.md`, seção 4). Hoje a medida é
 * por consumo de tokens ({@see ConsumoEmTokens}). Esta interface é o ponto de extensão para uma medida por peças,
 * quando existir a função "Redigir peça com a I.A.": basta uma nova implementação, sem mexer no
 * {@see \App\Inteligencia\Service\SaldoDeIaDaPessoa}.
 */
interface MedidaDoUsoDeIa
{
    /** 'tokens' ou 'pecas'. */
    public function unidade(): string;

    /** Quanto a pessoa tem de direito sem nenhum colega, na unidade da medida. */
    public function franquia(): int;

    /** Quanto cada colega cadastrado (com e-mail confirmado) acrescenta, na unidade da medida. */
    public function porColega(): int;

    /**
     * O que a pessoa já usou, na unidade da medida, só nos escritórios dados (os sem plano pago).
     *
     * @param list<int> $tenantIdsSemPlanoPago
     */
    public function usado(User $user, array $tenantIdsSemPlanoPago): int;
}
