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
 * chega RESOLVIDA (o chamador resolve 1× por caso), sem I/O, testável isoladamente.
 *
 * Regra por obrigação:
 *  - Congelada mas NÃO liquidada (Substituída por acordo / encargo legado travado): valor pactuado/
 *    fixado — fica INTACTA (não é dívida viva que uma quitação deva materializar).
 *  - `alocado >= exigível na data` → LIQUIDA: snapshot dos encargos na data + congela + `liquidadaEm`.
 *  - Senão, se estava liquidada → REABRE: volta a Viva (a correção desfez a quitação).
 *  - Senão (viva, pagamento parcial) → permanece Viva.
 */
final class ReconciliadorLiquidacao
{
    public function __construct(
        private readonly CalculadoraEncargos $calculadora,
    ) {
    }

    /**
     * @param iterable<Obrigacao> $obrigacoes          obrigações tocadas pelo pagamento (id ∈ alocado)
     * @param array<int, int>     $alocadoPorObrigacao obrigacaoId => Σ alocado FINAL (centavos)
     */
    public function reconciliar(
        ConfigEncargos $config,
        iterable $obrigacoes,
        array $alocadoPorObrigacao,
        \DateTimeImmutable $dataPagamento,
    ): void {
        foreach ($obrigacoes as $obrigacao) {
            // Substituída/legado congelado (congelada sem `liquidadaEm`): não é dívida viva a quitar.
            if ($obrigacao->encargosCongelados() && !$obrigacao->estaLiquidada()) {
                continue;
            }

            $id = $obrigacao->getId();
            $alocado = $id !== null ? ($alocadoPorObrigacao[$id] ?? 0) : 0;

            $encargos = $this->calculadora->calcular(
                $obrigacao->getValorOriginal(),
                $obrigacao->getVencimentoOriginal(),
                $config,
                $dataPagamento,
            );
            $exigivel = $obrigacao->getValorOriginal()
                + $encargos['juros'] + $encargos['multa'] + $encargos['correcao'];

            if ($alocado >= $exigivel) {
                $obrigacao->liquidar(
                    $encargos['juros'],
                    $encargos['multa'],
                    $encargos['correcao'],
                    $encargos['honorarios'],
                    $dataPagamento,
                );

                continue;
            }

            if ($obrigacao->estaLiquidada()) {
                $obrigacao->reabrir();
            }
        }
    }
}
