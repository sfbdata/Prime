<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Resultado da importação dos acordos detalhados (spec `cobranca-importar-acordos-detalhados.md`).
 * Serve ao dry-run (o que ACONTECERIA) e à confirmação (o que aconteceu) — os dois produzem a MESMA
 * estrutura, e é isso que permite conferir a projeção contra o efeito.
 *
 * Os dois números que a spec exige ver antes de qualquer escrita:
 * `principalReconciliadoCentavos()` (o principal que SAI do saldo — os R$ 680,00 do §1) e
 * `valorParcelasCriadasCentavos()` (o que ENTRA — os R$ 1.399,49 do §1). Nenhum dos dois é um
 * agregado opaco: `porAcordo()` mostra de onde cada centavo veio.
 */
final class ResultadoImportacaoAcordos
{
    /**
     * @param list<AcordoProcessado> $acordos
     * @param list<LinhaRejeitada>   $rejeitadas
     * @param list<string>           $parcelasLiquidadasNaPlanilha NNs com liquidação informada na fonte. A baixa
     *                                                            de pagamento está FORA de escopo (§5): o resumo
     *                                                            avisa para conferir à mão
     * @param list<string>           $situacoesDesconhecidas       "Acordo N: <situação>" não mapeada (§3.3)
     */
    public function __construct(
        public readonly array $acordos,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
        public readonly array $parcelasLiquidadasNaPlanilha = [],
        public readonly array $situacoesDesconhecidas = [],
    ) {
    }

    /** @return list<AcordoProcessado> */
    public function porAcordo(): array
    {
        return $this->acordos;
    }

    /** Abas puladas inteiras (acordo inexistente, caso encerrado) — nenhuma escrita saiu delas. */
    public function totalAbasIgnoradas(): int
    {
        return count(array_filter($this->acordos, static fn (AcordoProcessado $a): bool => $a->foiIgnorado()));
    }

    /** @return list<string> */
    public function nnsParcelasCriadas(): array
    {
        return $this->juntar(static fn (AcordoProcessado $a): array => $a->parcelasCriadas);
    }

    /** @return list<string> */
    public function nnsParcelasExistentes(): array
    {
        return $this->juntar(static fn (AcordoProcessado $a): array => $a->parcelasExistentes);
    }

    /** @return list<string> */
    public function nnsContasMarcadas(): array
    {
        return $this->juntar(static fn (AcordoProcessado $a): array => $a->contasMarcadas);
    }

    /** @return list<string> */
    public function nnsContasReconstruidas(): array
    {
        return $this->juntar(static fn (AcordoProcessado $a): array => $a->contasReconstruidas);
    }

    /** @return list<string> */
    public function nnsContasJaMarcadas(): array
    {
        return $this->juntar(static fn (AcordoProcessado $a): array => $a->contasJaMarcadas);
    }

    /** @return list<string> */
    public function divergenciasDeValor(): array
    {
        return $this->juntar(static fn (AcordoProcessado $a): array => $a->divergenciasDeValor);
    }

    /** @return list<string> */
    public function casadasSemCompetencia(): array
    {
        return $this->juntar(static fn (AcordoProcessado $a): array => $a->casadasSemCompetencia);
    }

    /** @return list<string> */
    public function contasRecusadas(): array
    {
        return $this->juntar(static fn (AcordoProcessado $a): array => $a->contasRecusadas);
    }

    /** @return list<string> */
    public function parcelasAmbiguas(): array
    {
        return $this->juntar(static fn (AcordoProcessado $a): array => $a->parcelasAmbiguas);
    }

    /** @return list<string> */
    public function situacoesDivergentes(): array
    {
        $divergentes = [];
        foreach ($this->acordos as $acordo) {
            if ($acordo->situacaoDivergente !== null) {
                $divergentes[] = $acordo->situacaoDivergente;
            }
        }

        return $divergentes;
    }

    /**
     * PRINCIPAL que sai do saldo exigível ao confirmar: a soma do `valorOriginal` das contas originais que
     * EXISTIAM abertas e passaram a ter `acordoSubstituto`. As contas reconstruídas (§3.2.1) NÃO entram —
     * elas nascem substituídas e nunca estiveram no saldo, então não o alteram.
     *
     * ⚠️ O saldo cai MAIS do que este número: junto com o principal saem os juros, a multa e a correção
     * que vinham correndo sobre ele ao vivo. É de propósito que o relatório mostre o principal — é o valor
     * conferível contra a planilha (§1: R$ 680,00) —, mas quem lê precisa saber que a queda real é maior.
     */
    public function principalReconciliadoCentavos(): int
    {
        return array_sum(array_map(static fn (AcordoProcessado $a): int => $a->principalReconciliadoCentavos, $this->acordos));
    }

    /** Valor que ENTRA no saldo: a soma das parcelas futuras que nenhum relatório enxergava. */
    public function valorParcelasCriadasCentavos(): int
    {
        return array_sum(array_map(static fn (AcordoProcessado $a): int => $a->valorParcelasCriadasCentavos, $this->acordos));
    }

    /**
     * Há algo que o operador PRECISA olhar antes de confirmar? Divergência de valor, casamento pelo
     * fallback legado, linha recusada, parcela ambígua, situação divergente/desconhecida, parcela que
     * consta liquidada ou aba ignorada. Tudo aqui é coisa em que a planilha e o sistema DISCORDAM — e a
     * importação escolheu a direção segura sozinha; quem decide o resto é gente.
     */
    public function temAvisos(): bool
    {
        return $this->divergenciasDeValor() !== []
            || $this->casadasSemCompetencia() !== []
            || $this->contasRecusadas() !== []
            || $this->parcelasAmbiguas() !== []
            || $this->situacoesDivergentes() !== []
            || $this->situacoesDesconhecidas !== []
            || $this->parcelasLiquidadasNaPlanilha !== []
            || $this->totalAbasIgnoradas() > 0;
    }

    public function totalRejeitadas(): int
    {
        return count($this->rejeitadas);
    }

    /**
     * @param callable(AcordoProcessado): list<string> $extrator
     *
     * @return list<string>
     */
    private function juntar(callable $extrator): array
    {
        $juntos = [];
        foreach ($this->acordos as $acordo) {
            foreach ($extrator($acordo) as $item) {
                $juntos[] = $item;
            }
        }

        return $juntos;
    }
}
