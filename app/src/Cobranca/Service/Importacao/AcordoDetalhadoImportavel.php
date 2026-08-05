<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Uma ABA do relatório "Acordos detalhados" já traduzida para o domínio: o cabeçalho do acordo, a
 * relação das contas originais e as parcelas geradas (spec `cobranca-importar-acordos-detalhados.md` §2).
 *
 * ⚠️ O texto do §3.1 ("o acordo NUNCA é criado a partir daqui") vale até a spec
 * `cobranca-importar-acordos-criar-acordo.md` (item 5): a aba cujo acordo não existe passa a **criá-lo**,
 * porque a única outra porta de criação é a Receitas — e ela só cria quando alguém PAGOU uma parcela.
 * Medido em 07/08: 38 dos 392 acordos declarados pela contábil não nasciam por causa disso.
 *
 * Com isso a `unidade` deixou de ser só um rótulo de conferência do dry-run: é ela que resolve o objeto
 * e, por ele, o caso onde o acordo será pendurado. Ver `objetoIdentificacao()`.
 */
final class AcordoDetalhadoImportavel
{
    /**
     * @param list<ContaOriginalImportavel> $contasOriginais
     * @param list<ParcelaAcordoImportavel> $parcelas
     */
    public function __construct(
        public readonly int $numero,
        public readonly string $unidade,
        public readonly string $sacado,
        public readonly string $situacao,
        public readonly ?\DateTimeImmutable $dataBase,
        public readonly ?\DateTimeImmutable $criadoEm,
        public readonly ?int $valorTotalContasOriginaisCentavos,
        public readonly ?int $valorFinalAcordadoCentavos,
        public readonly ?\DateTimeImmutable $emissao,
        public readonly array $contasOriginais,
        public readonly array $parcelas,
    ) {
    }

    /**
     * A identificação do `ObjetoCobranca` desta aba — a MESMA régua que a inadimplência e a receitas
     * aplicam à coluna `Unidade`, porque as três fontes trazem o texto no mesmo formato (medido em
     * 07/08, inclusive na forma com parênteses). Régua divergente aqui casaria o acordo no imóvel
     * errado, e é dívida.
     */
    public function objetoIdentificacao(): string
    {
        return IdentificacaoDaUnidade::separar($this->unidade)[0];
    }

    /**
     * O `t` do indicador `p/t` das parcelas — quantas parcelas o acordo tem ao todo, segundo a fonte.
     * `null` quando a aba não traz parcela alguma (não se inventa o total de um acordo sem parcela).
     */
    public function totalDeParcelas(): ?int
    {
        $totais = array_map(static fn (ParcelaAcordoImportavel $p): int => $p->total, $this->parcelas);

        return $totais === [] ? null : max($totais);
    }

    /**
     * A aba traz alguma parcela que a importação pode MATERIALIZAR como linha no sistema? Parcela que
     * consta liquidada nunca é criada (§5 da spec-mãe), então uma aba 100% liquidada não produz linha
     * nenhuma — é a forma de **todas** as 310 abas dos arquivos `*_LIQUIDADO` (627 de 627 parcelas com
     * data de liquidação, medido em 07/08).
     *
     * Quem consome isto é a criação do acordo, para não gravar um `numeroParcelasTotal` que a tela
     * compararia com zero linhas e transformaria num "faltam N parcelas" permanente e falso.
     */
    public function temParcelaAMaterializar(): bool
    {
        foreach ($this->parcelas as $parcela) {
            if (!$parcela->constaLiquidada) {
                return true;
            }
        }

        return false;
    }

    /** Soma da coluna "Valor original" das contas originais — para conferir contra o cabeçalho. */
    public function somaContasOriginaisCentavos(): int
    {
        return array_sum(array_map(static fn (ContaOriginalImportavel $c): int => $c->valorCentavos, $this->contasOriginais));
    }

    /** Soma das parcelas — para conferir contra "Valor final acordado" do cabeçalho. */
    public function somaParcelasCentavos(): int
    {
        return array_sum(array_map(static fn (ParcelaAcordoImportavel $p): int => $p->valorCentavos, $this->parcelas));
    }
}
