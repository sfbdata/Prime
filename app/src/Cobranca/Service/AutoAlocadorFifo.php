<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\AlocacaoPagamentoInput;
use App\Cobranca\DTO\LinhaAlocacaoFifo;
use App\Cobranca\DTO\PreviaAlocacaoFifo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Exception\PagamentoExcedeSaldoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Entity\Tenant\Tenant;

/**
 * Auto-alocação FIFO de um pagamento (Ajuste 6). A partir do BRUTO pago, separa a parte-dívida via
 * CalculadoraHonorarios::ratearPagamento (§18) e a distribui pelas obrigações EXIGÍVEIS na ordem em
 * que `doCasoExigiveis` as entrega — `vencimentoOriginal ASC`, mais antiga/vencida primeiro. Serviço
 * read-only: NÃO persiste; só deriva os `AlocacaoPagamentoInput` que o AlocadorPagamento revalida
 * (Σ == parte-dívida, invariável 20) e materializa.
 *
 * Teto de alocação = saldo exigível derivado do caso, IDÊNTICO a `CalculadoraSaldo::saldoExigivel`
 * (Σ valorExigivel − Σ alocado − liquidação por-CASO), sem piso por obrigação — assim uma obrigação
 * super-alocada (que o modo manual permite: `montar` não tem cap por-obrigação) não infla o teto. Se
 * a parte-dívida excede esse saldo, BLOQUEIA (decisão D1) — nada de saldo real negativo. Como a
 * parte-dívida é inteira e, quando cabe, é ≤ saldo ≤ Σ salas, a distribuição gulosa fecha EXATAMENTE
 * em centavos (a última obrigação tocada leva o resíduo); nenhuma sobra fracionária surge.
 *
 * Na correção de pagamento, as alocações do PRÓPRIO pagamento (que serão reescritas) voltam à sala,
 * para não bloquear por engano.
 */
final class AutoAlocadorFifo
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly LiquidacaoRepository $liquidacaoRepository,
        private readonly CalculadoraHonorarios $calculadoraHonorarios,
        private readonly EncargosVivos $encargosVivos,
        private readonly ResolvedorConfigEncargos $resolvedorConfig,
    ) {
    }

    /**
     * Deriva a divisão dívida/honorários e a quebra FIFO SEM lançar — insumo da prévia ao vivo.
     */
    public function derivar(CasoCobranca $caso, int $valorPago, Tenant $tenant, ?Pagamento $pagamentoEmCorrecao = null): PreviaAlocacaoFifo
    {
        [$valorDivida, $valorHonorarios] = $this->calculadoraHonorarios->ratearPagamento($caso, $valorPago);

        $exigiveis = $this->obrigacaoRepository->doCasoExigiveis($caso);

        // Encargos AO VIVO (spec §6.2/INV-V5): hidrata EM MEMÓRIA as obrigacoes VIVAS para HOJE antes
        // de derivar saldo/salas — a alocacao FIFO opera sobre o MESMO exigivel vivo que o saldo e a
        // tela. Congeladas (Liquidada/Substituida) mantem o snapshot. Config resolvida 1x por caso.
        // Nota (§6.5): `derivar` alimenta tambem o caminho de ESCRITA (RegistrarPagamento/CorrigirPagamento
        // dao flush depois); as colunas de encargo de uma Viva hidratada podem ir ao banco como CACHE, e
        // sao sempre reescritas em memoria na proxima leitura — a fonte de verdade da Viva e sempre o vivo.
        $this->encargosVivos->hidratar($this->resolvedorConfig->resolverDoCaso($caso), $exigiveis);

        $casoId = $caso->getId();
        $alocado = $casoId === null ? [] : $this->alocacaoRepository->somasPorObrigacaoDosCasos([$casoId], $tenant);

        // Correção: as alocações do próprio pagamento serão reescritas → devolvê-las à sala disponível.
        if ($pagamentoEmCorrecao !== null) {
            foreach ($pagamentoEmCorrecao->getAlocacoes() as $alocacao) {
                $id = $alocacao->getObrigacao()?->getId();

                if ($id !== null) {
                    $alocado[$id] = ($alocado[$id] ?? 0) - $alocacao->getValor();
                }
            }
        }

        // Por obrigação (na ordem FIFO de `doCasoExigiveis`) derivamos DOIS números:
        // - `saldoBruto` (pode ser negativo sob super-alocação, sem piso por obrigação) alimenta o TETO
        //   e espelha EXATAMENTE `CalculadoraSaldo::saldoExigivel` (Σ valorExigivel − Σ alocado − liq).
        //   Sem isso, uma obrigação super-alocada (que o modo manual permite — `montar` não tem cap
        //   por-obrigação) inflaria o teto e D1 liberaria pagamento que zera o saldo real p/ negativo.
        // - `sala` (piso 0 E teto no próprio valorExigivel) alimenta a DISTRIBUIÇÃO — nunca aloca sala
        //   negativa nem acima do exigível da obrigação (defesa contra alocado espúrio negativo).
        $saldoBruto = 0;
        $salaTotal = 0;
        $salas = [];

        foreach ($exigiveis as $obrigacao) {
            $id = $obrigacao->getId();
            $jaAlocado = $id !== null ? ($alocado[$id] ?? 0) : 0;
            $valorExigivel = $obrigacao->valorExigivel();

            $saldoBruto += $valorExigivel - $jaAlocado;
            $sala = max(0, min($valorExigivel, $valorExigivel - $jaAlocado));

            $salaTotal += $sala;
            $salas[] = [$obrigacao, $sala];
        }

        // Teto real: abate a liquidação por-caso. Em operação correta (alocado ≥ 0) vale
        // `saldoDisponivel ≤ salaTotal`, então o 2º termo do guard nunca morde. Sob alocado espúrio
        // negativo (impedido pela ordem da Fatia 2: alocar ANTES de limpar/flush) `salaTotal` fica
        // MENOR que o saldo — o 2º termo fecha o buraco: garante `Σ linhas == valorDivida` sempre que
        // `excede=false`, inclusive no caminho de leitura (prévia), sem depender do `montar`.
        $saldoDisponivel = $saldoBruto - $this->liquidacaoRepository->totalReconhecidoNoCaso($caso);

        if ($valorDivida > $saldoDisponivel || $valorDivida > $salaTotal) {
            $excedeEm = $valorDivida - min($saldoDisponivel, $salaTotal);

            return new PreviaAlocacaoFifo($valorPago, $valorDivida, $valorHonorarios, $saldoDisponivel, true, $excedeEm, []);
        }

        // Distribuição gulosa: valorDivida ≤ min(saldoDisponivel, salaTotal) → cabe exato.
        $restante = $valorDivida;
        $linhas = [];

        foreach ($salas as [$obrigacao, $sala]) {
            if ($restante <= 0) {
                break;
            }

            $take = min($sala, $restante);

            if ($take <= 0) {
                continue;
            }

            $linhas[] = new LinhaAlocacaoFifo(
                (int) $obrigacao->getId(),
                $obrigacao->getDescricao(),
                $obrigacao->getVencimentoOriginal(),
                $take,
            );
            $restante -= $take;
        }

        return new PreviaAlocacaoFifo($valorPago, $valorDivida, $valorHonorarios, $saldoDisponivel, false, 0, $linhas);
    }

    /**
     * Deriva e converte em `AlocacaoPagamentoInput[]` para o pipeline de escrita — LANÇA se excede.
     *
     * @return AlocacaoPagamentoInput[]
     *
     * @throws PagamentoExcedeSaldoException
     */
    public function alocar(CasoCobranca $caso, int $valorPago, Tenant $tenant, ?Pagamento $pagamentoEmCorrecao = null): array
    {
        $previa = $this->derivar($caso, $valorPago, $tenant, $pagamentoEmCorrecao);

        if ($previa->excede) {
            throw new PagamentoExcedeSaldoException($previa->valorDivida, $previa->saldoDisponivel);
        }

        return array_map(
            static function (LinhaAlocacaoFifo $linha): AlocacaoPagamentoInput {
                $input = new AlocacaoPagamentoInput();
                $input->obrigacaoId = $linha->obrigacaoId;
                $input->valor = $linha->valor;

                return $input;
            },
            $previa->linhas,
        );
    }
}
