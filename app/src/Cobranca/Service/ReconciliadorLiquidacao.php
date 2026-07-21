<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Obrigacao;

/**
 * Ponto ÚNICO da regra de quitação no modelo "encargos ao vivo" (spec §6.3): depois que um pagamento
 * é alocado, decide — por obrigação tocada — se ela QUITA ou REABRE, comparando o alocado FINAL com o
 * exigível calculado na DATA DO PAGAMENTO (não em "hoje": o relógio para na liquidação, spec §4/§5).
 *
 * Compartilhado por `RegistrarPagamentoUseCase` (quita ao pagar) e `CorrigirPagamentoUseCase` (pode
 * quitar OU reabrir, conforme a correção sobe ou desce o alocado). Aplicador puro: a `ConfigEncargos`
 * chega RESOLVIDA como BASE DO CASO (o chamador resolve 1× por caso, via `resolverDoCaso`), sem I/O.
 *
 * Por OBRIGAÇÃO, aplica por cima o overlay do 3º nível da cascata
 * (`ResolvedorConfigEncargos::aplicarObrigacao`, spec "taxa por-obrigação") antes de calcular o
 * exigível — a taxa própria da obrigação (se houver) vence; campo ausente herda da base do caso. É o
 * MESMO padrão do `EncargosVivos`: sem isso, a quitação/snapshot da liquidação divergiria do saldo/
 * FIFO/tela, que já aplicam o overlay — corrompendo o valor congelado assim que houver override.
 *
 * Regra por obrigação:
 *  - Substituída por acordo VIGENTE: valor PACTUADO (snapshot da data do acordo) — fica INTACTA, não é
 *    dívida viva. Detectada por `acordoSubstituto` vigente (NÃO por `encargosCongelados()`: no design
 *    "ao vivo" a substituída é materializada mas NÃO congelada, para voltar a crescer se o acordo romper).
 *  - Congelada mas NÃO liquidada (encargo legado travado): valor fixado — fica INTACTA.
 *  - `alocado >= exigível na data` e AINDA NÃO liquidada → LIQUIDA: snapshot na data + congela + `liquidadaEm`.
 *  - `alocado >= exigível` e JÁ liquidada → NÃO re-liquida: preserva o snapshot original (INV-V2 —
 *    Liquidada nunca recalcula; senão corrigir um pagamento reescreveria a data/valor de uma obrigação
 *    quitada por OUTRO pagamento).
 *  - `alocado < exigível` e estava liquidada → REABRE: volta a Viva (a correção desfez a quitação).
 *  - Senão (viva, pagamento parcial) → permanece Viva.
 */
final class ReconciliadorLiquidacao
{
    public function __construct(
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
    ) {
    }

    /**
     * `$configCaso` é a config resolvida do CASO (`resolverDoCaso`, 1× por caso — sem N+1); por
     * obrigação, aplicamos por cima o overlay da própria obrigação (spec "taxa por-obrigação") antes
     * de calcular o exigível — a mesma base que o chamador já passava, sem dupla aplicação.
     *
     * @param iterable<Obrigacao> $obrigacoes          obrigações tocadas pelo pagamento (id ∈ alocado)
     * @param array<int, int>     $alocadoPorObrigacao obrigacaoId => Σ alocado FINAL (centavos)
     */
    public function reconciliar(
        ConfigEncargos $configCaso,
        iterable $obrigacoes,
        array $alocadoPorObrigacao,
        \DateTimeImmutable $dataPagamento,
    ): void {
        foreach ($obrigacoes as $obrigacao) {
            // Substituída por acordo VIGENTE: valor pactuado (snapshot da data do acordo), fora do
            // exigível — nunca é quitada por um pagamento. Detectada pelo acordo substituto vigente, não
            // por `encargosCongelados()` (no design "ao vivo" ela é materializada mas NÃO congelada).
            if ($obrigacao->getAcordoSubstituto()?->getStatus()->ehVigente() ?? false) {
                continue;
            }

            // Congelada mas não liquidada (encargo legado travado): valor fixado — não é dívida viva.
            if ($obrigacao->encargosCongelados() && !$obrigacao->estaLiquidada()) {
                continue;
            }

            $id = $obrigacao->getId();
            $alocado = $id !== null ? ($alocadoPorObrigacao[$id] ?? 0) : 0;

            $config = $this->resolvedor->aplicarObrigacao($configCaso, $obrigacao);

            $encargos = $this->calculadora->calcular(
                $obrigacao->getValorOriginal(),
                $obrigacao->getVencimentoOriginal(),
                $config,
                $dataPagamento,
            );
            $exigivel = $obrigacao->getValorOriginal()
                + $encargos['juros'] + $encargos['multa'] + $encargos['correcao'];

            if ($alocado >= $exigivel) {
                // Só materializa o snapshot na TRANSIÇÃO Viva → Liquidada. Se já estava liquidada e segue
                // paga, preserva o snapshot original (INV-V2): recalcular reescreveria a data/valor
                // congelados de uma dívida quitada — possivelmente por OUTRO pagamento — ao corrigir este.
                if (!$obrigacao->estaLiquidada()) {
                    $obrigacao->liquidar(
                        $encargos['juros'],
                        $encargos['multa'],
                        $encargos['correcao'],
                        $encargos['honorarios'],
                        $dataPagamento,
                    );
                }

                continue;
            }

            if ($obrigacao->estaLiquidada()) {
                $obrigacao->reabrir();
            }
        }
    }
}
