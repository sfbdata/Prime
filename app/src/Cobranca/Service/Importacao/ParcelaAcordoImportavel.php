<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Uma PARCELA da seção "Parcelas das contas geradas pelo acordo", já agregada por NN (spec
 * `cobranca-importar-acordos-detalhados.md` §2/§3.1).
 *
 * Um mesmo NN de parcela ocupa VÁRIAS linhas na fonte — a composição do boleto: uma linha por taxa de
 * competência renegociada, mais juros, multa, desconto e honorário. O valor da parcela é a SOMA da
 * coluna "Valor acordado" de todas elas (inclusive o desconto, que vem negativo).
 *
 * Medido contra a fonte real (2026-07-29): essa soma reproduz ao centavo o `somaColunaValorCentavos`
 * que o `TopLifeInadimplenciaAdapter` calcula para a MESMA parcela quando ela aparece na inadimplência
 * (ex.: NN 61600 = R$ 199,39 nas duas fontes). É essa igualdade que garante que a parcela criada aqui
 * não vire uma segunda obrigação quando a inadimplência do mês seguinte trouxer o mesmo boleto.
 *
 * A COMPETÊNCIA é a da coluna "Competência" da seção (o mês do boleto da parcela), NÃO a competência
 * que aparece no fim da classe de conta (o mês da taxa renegociada, que é outra coisa). Também medido:
 * a coluna bate com a competência que a inadimplência traz para o mesmo NN — é a chave de idempotência.
 */
final class ParcelaAcordoImportavel
{
    /**
     * @param list<string> $classes classes de conta que compõem o boleto, na ordem da fonte
     */
    public function __construct(
        public readonly int $acordoNumero,
        public readonly string $nn,
        public readonly int $numero,
        public readonly int $total,
        public readonly string $competencia,
        public readonly \DateTimeImmutable $vencimento,
        public readonly int $valorCentavos,
        public readonly array $classes,
        public readonly bool $constaLiquidada,
    ) {
        CompetenciaImportada::exigirValida($competencia, $nn);
    }

    /**
     * Descrição no MESMO formato do importador de inadimplência (`BoletoImportavel::descricao()` +
     * `observacao()`): classes presentes, competência e o texto do acordo. Uma parcela criada por aqui
     * fica indistinguível de uma criada por lá — é o que permite que os dois caminhos convivam.
     */
    public function descricao(): string
    {
        $rotulo = implode(' + ', array_values(array_unique($this->classes)));
        $base = trim("{$rotulo} — competência {$this->competencia}", ' —');

        return $base . sprintf(' | Acordo %d - Parc. %d/%d', $this->acordoNumero, $this->numero, $this->total);
    }
}
