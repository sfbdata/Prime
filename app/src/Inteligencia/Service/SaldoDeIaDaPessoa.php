<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Contribua\Service\PlanoPagoDoEscritorio;
use App\Entity\Auth\User;
use App\Indicacao\Service\ColegasCadastradosPorIndicacao;
use App\Inteligencia\DTO\SaldoDeIaOutput;
use App\Inteligencia\Service\Saldo\MedidaDoUsoDeIa;
use App\Repository\UserTenantRepository;

/**
 * Saldo de I.A. gratuita por PESSOA (spec `ia-liberada-por-indicacao.md`, seções 3 e 4). Calculado, sem tabela:
 *
 * - direito = franquia + por colega × colegas que ela indicou (cadastrados, com e-mail confirmado);
 * - usado = o que a {@see MedidaDoUsoDeIa} soma, só nos escritórios SEM plano pago hoje (se o escritório passa a
 *   pagar, o que foi gasto lá deixa de contar).
 *
 * Os escritórios considerados são os dos vínculos ativos da pessoa. Pessoa só em escritório pago: `seAplica` falso.
 */
// Não-final: permite substituição por mock nos testes do portão.
class SaldoDeIaDaPessoa
{
    public const PECAS_DA_FRANQUIA = 3;
    /** A partir deste percentual a barra avisa que está perto do fim. */
    private const PERCENTUAL_PERTO = 80;

    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly PlanoPagoDoEscritorio $planos,
        private readonly ColegasCadastradosPorIndicacao $colegas,
        private readonly MedidaDoUsoDeIa $medida,
    ) {
    }

    public function de(User $user): SaldoDeIaOutput
    {
        $tenantIds = [];
        foreach ($this->vinculos->findActiveByUser($user) as $vinculo) {
            $tenantIds[] = (int) $vinculo->getTenant()->getId();
        }
        $semPlano = $this->planos->semPlanoPago(array_values(array_unique($tenantIds)));

        $colegas = $this->colegas->contar((int) $user->getId());
        $franquia = $this->medida->franquia();
        $direito = $franquia + $this->medida->porColega() * $colegas;
        $usado = $semPlano === [] ? 0 : $this->medida->usado($user, $semPlano);
        $emTokens = $this->medida->unidade() === 'tokens';

        $percentual = $direito > 0 ? (int) min(100, intdiv($usado * 100, $direito)) : 100;

        return new SaldoDeIaOutput(
            seAplica: $semPlano !== [],
            tokensUsados: $usado,
            tokensDireito: $direito,
            pecasUsadas: $emTokens ? round($usado * self::PECAS_DA_FRANQUIA / $franquia, 1) : (float) $usado,
            pecasDireito: self::PECAS_DA_FRANQUIA * (1 + $colegas),
            percentual: $percentual,
            esgotado: $usado >= $direito,
            perto: $percentual >= self::PERCENTUAL_PERTO,
            colegasCadastrados: $colegas,
            unidade: $this->medida->unidade(),
        );
    }
}
