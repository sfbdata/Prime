<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\FormaHonorarios;

/**
 * Resto da SPEC §18 — o que dela ainda vale depois que o honorário passou a viver DENTRO da dívida
 * (spec `cobranca-honorario-no-total.md`, decisão do dono em 19/08).
 *
 * 🔴 **TRÊS MÉTODOS FORAM APAGADOS AQUI, e apagar era o conserto:**
 * - `ratearPagamento()` — separava o pagamento em `[dívida, honorário]` por `p/(1+p)` e só a
 *   parte-dívida abatia. Com o honorário dentro do exigível isso deixava a dívida curta para sempre:
 *   **nenhuma dívida quitava**.
 * - `brutoParaRecuperar()` — o "gross-up" do prefill (`D × (1+p)`). O exigível já contém o
 *   honorário; multiplicar de novo cobraria honorário sobre honorário.
 * - `projetados()` — alíquota lisa sobre o saldo. Além de dobrar o honorário (o saldo já o contém),
 *   ERRA a fonte: medido contra o rodapé da contabilidade, a alíquota erra R$ 46.747,38 (37% a mais)
 *   porque ignora a carência de 30 dias e as parcelas de acordo; o honorário gravado erra R$ 515,32.
 *
 * Não foram apagados por estarem sem uso — foram apagados porque **voltar a chamá-los reintroduz a
 * dupla contagem em silêncio**. O histórico está no git para quem precisar do modelo antigo.
 *
 * O que sobra é a política de honorários como CONFIGURAÇÃO e a apuração do realizado por forma. A
 * ALÍQUOTA vem da cascata do OBJETO via `ResolvedorConfigEncargos::resolverDoObjeto`
 * (`objeto.taxaHonorariosBp ?? carteira`) — a MESMA fonte que o exigível usa, então os dois nunca
 * divergem. A FORMA vem sempre da CARTEIRA (`caso→objeto→carteira`), não é sobreponível.
 *
 * Serviço read-only, sem persistir. Toda aritmética em CENTAVOS inteiros, arredondamento
 * meio-para-cima, sem float na conta final.
 */
final class CalculadoraHonorarios
{
    public function __construct(private readonly ResolvedorConfigEncargos $resolvedorConfig)
    {
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
