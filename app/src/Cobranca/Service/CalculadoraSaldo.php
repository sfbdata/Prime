<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;

/**
 * Deriva o saldo do Caso de Cobrança a partir das obrigações (SPEC §10, invariável 20): o saldo
 * NUNCA é digitado nem persistido — é sempre calculado dos eventos/valores. Toda aritmética em
 * CENTAVOS inteiros. Serviço read-only (não persiste).
 *
 * Escopo da Etapa 2: o exigível considera obrigação original + encargos reconhecidos. Pagamentos,
 * liquidações (Etapa 3) e substituição por acordo (Etapa 4) entram como subtração/exclusão aqui
 * nas etapas seguintes — a fonte de verdade continua sendo as obrigações e movimentos.
 */
final class CalculadoraSaldo
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
    ) {
    }

    /** Saldo exigível do caso, em centavos: soma do valor exigível das obrigações do caso. */
    public function saldoExigivel(CasoCobranca $caso): int
    {
        $total = 0;

        foreach ($this->obrigacaoRepository->doCaso($caso) as $obrigacao) {
            $total += $obrigacao->valorExigivel();
        }

        return $total;
    }

    /**
     * Saldo vencido do caso, em centavos: exigível restrito às obrigações com vencimento até hoje
     * (inclusive). `$hoje` é injetável para testes determinísticos.
     */
    public function saldoVencido(CasoCobranca $caso, ?\DateTimeImmutable $hoje = null): int
    {
        $hoje ??= new \DateTimeImmutable();
        $total = 0;

        foreach ($this->obrigacaoRepository->doCaso($caso) as $obrigacao) {
            if ($obrigacao->getVencimentoOriginal() <= $hoje) {
                $total += $obrigacao->valorExigivel();
            }
        }

        return $total;
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
