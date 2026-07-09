<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;

/**
 * Deriva o saldo do Caso de Cobrança a partir das obrigações e dos movimentos (SPEC §10,
 * invariável 20): o saldo NUNCA é digitado nem persistido — é sempre calculado dos eventos/valores.
 * Toda aritmética em CENTAVOS inteiros. Serviço read-only (não persiste).
 *
 * Escopo acumulado até a Etapa 3: o exigível é obrigação (original + encargos reconhecidos) MENOS
 * os pagamentos alocados MENOS as liquidações reconhecidas. A exclusão de obrigações substituídas
 * por acordo entra na Etapa 4 (extensão orquestrador-owned). A fonte de verdade continua sendo as
 * obrigações e os movimentos.
 */
final class CalculadoraSaldo
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly LiquidacaoRepository $liquidacaoRepository,
    ) {
    }

    /**
     * Saldo exigível do caso, em centavos: Σ exigível das obrigações − Σ pagamentos alocados
     * − Σ liquidações reconhecidas (SPEC §10/§11).
     */
    public function saldoExigivel(CasoCobranca $caso): int
    {
        $bruto = 0;

        foreach ($this->obrigacaoRepository->doCaso($caso) as $obrigacao) {
            $bruto += $obrigacao->valorExigivel();
        }

        $pago = $this->alocacaoRepository->totalAlocadoNoCaso($caso);
        $liquidado = $this->liquidacaoRepository->totalReconhecidoNoCaso($caso);

        return $bruto - $pago - $liquidado;
    }

    /**
     * Saldo vencido do caso, em centavos: exigível das obrigações vencidas até hoje (inclusive),
     * abatido dos pagamentos alocados a essas obrigações e das liquidações do caso (que amortizam
     * o vencido primeiro), com piso 0. `$hoje` é injetável para testes determinísticos.
     */
    public function saldoVencido(CasoCobranca $caso, ?\DateTimeImmutable $hoje = null): int
    {
        $hoje ??= new \DateTimeImmutable();
        $bruto = 0;
        $idsVencidas = [];

        foreach ($this->obrigacaoRepository->doCaso($caso) as $obrigacao) {
            if ($obrigacao->getVencimentoOriginal() <= $hoje) {
                $bruto += $obrigacao->valorExigivel();
                $idVencida = $obrigacao->getId();

                if ($idVencida !== null) {
                    $idsVencidas[] = $idVencida;
                }
            }
        }

        $tenant = $caso->getTenant();

        $pagoVencidas = $tenant === null
            ? 0
            : $this->alocacaoRepository->totalAlocadoEmObrigacoes($idsVencidas, $tenant);
        $liquidado = $this->liquidacaoRepository->totalReconhecidoNoCaso($caso);

        return max(0, $bruto - $pagoVencidas - $liquidado);
    }

    /**
     * Saldo consolidado do objeto (SPEC §6, modo B): soma do exigível de TODOS os casos ativos do
     * objeto, em centavos. Nenhum caso isolado representa o total do objeto.
     */
    public function saldoConsolidadoObjeto(ObjetoCobranca $objeto): int
    {
        $total = 0;

        foreach ($this->casoRepository->casosAtivosDoObjeto($objeto) as $caso) {
            $total += $this->saldoExigivel($caso);
        }

        return $total;
    }
}
