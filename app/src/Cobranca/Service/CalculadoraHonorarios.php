<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\FormaHonorarios;

/**
 * Calcula honorários advocatícios (SPEC §18) a partir do SNAPSHOT do caso (`formaHonorarios` +
 * `percentualHonorarios`) — nunca da carteira atual, para não recalcular casos antigos
 * (§18.2/§18.3). Serviço read-only, sem persistir. Honorários são SEMPRE separados da dívida do
 * credor (invariável 18) e NÃO entram na própria base (§18.5).
 *
 * Toda aritmética em CENTAVOS inteiros, com arredondamento meio-para-cima e SEM float na conta
 * final (o percentual decimal só é convertido para basis points uma vez). O rateio fecha exatamente
 * em centavos por construção: `divida = total − honorarios`.
 */
final class CalculadoraHonorarios
{
    /**
     * Honorários PROJETADOS sobre uma base de dívida reconhecida (centavos): `base · p`. Zero para
     * `sem_percentual` ou base não positiva. Honorários não entram na base (§18.5).
     */
    public function projetados(CasoCobranca $caso, int $baseDividaCentavos): int
    {
        if ($baseDividaCentavos <= 0) {
            return 0;
        }

        $pb = $this->basisPoints($caso);

        if ($pb === 0) {
            return 0;
        }

        return $this->arredondarFracao($baseDividaCentavos * $pb, 10000);
    }

    /**
     * Rateio PROPORCIONAL de um pagamento (centavos) na forma `acrescido_divida` (SPEC §18): o
     * devedor paga dívida + honorários juntos; separa em `[valorDivida, valorHonorarios]` que somam
     * exatamente ao total (`hon = total · p/(1+p)`; `divida = total − hon`). Nas demais formas o
     * devedor paga só a dívida → `[total, 0]` (honorários tratados à parte).
     *
     * @return array{0:int,1:int} [valorDivida, valorHonorarios]
     */
    public function ratearPagamento(CasoCobranca $caso, int $valorTotalPagoCentavos): array
    {
        if ($valorTotalPagoCentavos <= 0 || $caso->getFormaHonorarios() !== FormaHonorarios::AcrescidoDivida) {
            return [$valorTotalPagoCentavos, 0];
        }

        $pb = $this->basisPoints($caso);

        if ($pb === 0) {
            return [$valorTotalPagoCentavos, 0];
        }

        // fração de honorários no total = p/(1+p) = pb/(10000+pb).
        $honorarios = $this->arredondarFracao($valorTotalPagoCentavos * $pb, 10000 + $pb);
        $divida = $valorTotalPagoCentavos - $honorarios;

        return [$divida, $honorarios];
    }

    /**
     * Honorários REALIZADOS a partir da dívida efetivamente recuperada (centavos): `recuperado · p`
     * para formas com percentual; `0` para `sem_percentual`. É a base do "honorário realizado" do
     * relatório (§18.7) — no `cobrado_separado` é o honorário GERADO (recebimento efetivo é do
     * futuro Financeiro, §18.8/§19).
     */
    public function realizadosSobreRecuperacao(CasoCobranca $caso, int $valorRecuperadoDividaCentavos): int
    {
        if ($valorRecuperadoDividaCentavos <= 0) {
            return 0;
        }

        $pb = $this->basisPoints($caso);

        if ($pb === 0) {
            return 0;
        }

        return $this->arredondarFracao($valorRecuperadoDividaCentavos * $pb, 10000);
    }

    /**
     * Percentual do snapshot do caso em basis points (10.00% → 1000). Zero quando a forma não usa
     * percentual (`sem_percentual`) ou quando não há percentual configurado.
     */
    private function basisPoints(CasoCobranca $caso): int
    {
        if ($caso->getFormaHonorarios()->exigePercentual() === false) {
            return 0;
        }

        $percentual = $caso->getPercentualHonorarios();

        if ($percentual === null) {
            return 0;
        }

        return (int) round(((float) $percentual) * 100);
    }

    /** Divisão inteira com arredondamento meio-para-cima (numerador e denominador positivos). */
    private function arredondarFracao(int $numerador, int $denominador): int
    {
        return intdiv($numerador + intdiv($denominador, 2), $denominador);
    }
}
