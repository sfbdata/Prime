<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\FormaHonorarios;

/**
 * Calcula honorários advocatícios (SPEC §18) a partir da política EFETIVA de honorários do caso
 * (spec "cascata de encargos ao vivo sem snapshot" §3.2/§9-T2) — não mais do snapshot do caso.
 * Serviço read-only, sem persistir. Honorários são SEMPRE separados da dívida do credor
 * (invariável 18) e NÃO entram na própria base (§18.5).
 *
 * A política tem DUAS fontes distintas, cada uma no seu nível certo da cascata (design #9-T2):
 * - **FORMA** (o comportamento do split — `acrescido_divida`/`retido_recuperado`/...): vem SEMPRE da
 *   CARTEIRA (`caso→objeto→carteira`). Não é sobreponível no objeto/obrigação — só a ALÍQUOTA é.
 * - **ALÍQUOTA** (em basis points): vem da cascata do OBJETO via `ResolvedorConfigEncargos::resolverDoObjeto`
 *   (`objeto.taxaHonorariosBp ?? carteira`), a MESMA fonte que o exigível ao vivo usa — split e exigível
 *   nunca mais divergem (fecha a divergência I-1 que a T1 introduziu: 194 casos legados com honorário
 *   fotografado a 10% no caso enquanto a carteira já estava a 20%/15%).
 *
 * Toda aritmética em CENTAVOS inteiros, com arredondamento meio-para-cima e SEM float na conta
 * final (o percentual decimal só é convertido para basis points uma vez, na borda do resolvedor). O
 * rateio fecha exatamente em centavos por construção: `divida = total − honorarios`.
 */
final class CalculadoraHonorarios
{
    public function __construct(private readonly ResolvedorConfigEncargos $resolvedorConfig)
    {
    }

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
        if ($valorTotalPagoCentavos <= 0 || $this->forma($caso) !== FormaHonorarios::AcrescidoDivida) {
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
     * INVERSO de `ratearPagamento` (Ajuste 10, spec §5.1): dado o quanto se quer recuperar de DÍVIDA
     * (centavos), devolve o valor BRUTO a cobrar do devedor — dívida + honorários — na forma
     * `acrescido_divida`: `T = D · (10000+pb)/10000`. Nas demais formas (e sem percentual) o devedor paga
     * só a dívida, então devolve o próprio alvo — espelhando `ratearPagamento`.
     *
     * Existe porque o alvo é INVISÍVEL para o gestor: ele quer quitar uma obrigação de R$1.200 e precisa
     * digitar R$1.320. Pré-preencher R$1.200 rateia para R$1.090,91 e a obrigação NÃO quita.
     *
     * Garantia (coberta por teste): `ratearPagamento($caso, brutoParaRecuperar($caso, $d))[0] === $d`.
     */
    public function brutoParaRecuperar(CasoCobranca $caso, int $dividaAlvoCentavos): int
    {
        if ($dividaAlvoCentavos <= 0 || $this->forma($caso) !== FormaHonorarios::AcrescidoDivida) {
            return $dividaAlvoCentavos;
        }

        $pb = $this->basisPoints($caso);

        if ($pb === 0) {
            return $dividaAlvoCentavos;
        }

        return $this->arredondarFracao($dividaAlvoCentavos * (10000 + $pb), 10000);
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
     * FORMA de honorários efetiva de um caso: SEMPRE vem da CARTEIRA (`caso→objeto→carteira`) —
     * decisão de design #9-T2, não é sobreponível em nível de objeto/obrigação (só a ALÍQUOTA é).
     * Público porque os leitores de display (`MontarDashboardCobrancaUseCase`) precisam da MESMA
     * fonte para nunca divergir da forma usada aqui no split. Caso órfão de objeto/carteira degrada
     * para `SemPercentual` (neutro, mesmo fallback seguro do resto da cascata).
     */
    public function forma(CasoCobranca $caso): FormaHonorarios
    {
        return $caso->getObjeto()?->getCarteira()?->getFormaHonorarios() ?? FormaHonorarios::SemPercentual;
    }

    /**
     * Alíquota efetiva em basis points (10.00% → 1000): zero quando a forma não usa percentual
     * (`sem_percentual`) ou quando o caso não tem objeto (órfão). Delega ao
     * `ResolvedorConfigEncargos::resolverDoObjeto` — a MESMA cascata Carteira → Objeto que o exigível
     * ao vivo usa, para o split nunca divergir do que a tela mostra como exigível de honorário.
     */
    private function basisPoints(CasoCobranca $caso): int
    {
        if ($this->forma($caso)->exigePercentual() === false) {
            return 0;
        }

        $objeto = $caso->getObjeto();

        return $objeto === null ? 0 : $this->resolvedorConfig->resolverDoObjeto($objeto)->taxaHonorariosBp;
    }

    /** Divisão inteira com arredondamento meio-para-cima (numerador e denominador positivos). */
    private function arredondarFracao(int $numerador, int $denominador): int
    {
        return intdiv($numerador + intdiv($denominador, 2), $denominador);
    }
}
