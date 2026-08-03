<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * UM recebimento legível da fonte "Receitas detalhadas por unidade/cliente" — spec
 * `docs/specs/cobranca-importar-receitas.md`. Corresponde a UM `Pagamento` do domínio.
 *
 * Agrega as ~2,3 linhas de classe de conta do mesmo NN (medido: cada NN tem exatamente UMA data de
 * recebimento nos dois arquivos, então NN identifica o recebimento). Os três baldes já saem prontos na
 * decomposição que a entidade `Pagamento` usa — a contabilidade rateou, o sistema não adivinha.
 *
 * Valores em CENTAVOS, vindos da coluna I (`Valor recebido`), nunca da H (decisão R2 da spec).
 */
final class ReceitaImportavel
{
    /**
     * @param list<array{classe: string, valor: int}> $linhas detalhe por classe, para o relatório e a auditoria
     */
    public function __construct(
        public readonly string $nn,
        public readonly string $objetoIdentificacao,
        public readonly ?string $unidadeMetadata,
        public readonly string $sacadoNome,
        public readonly string $competencia,
        public readonly \DateTimeImmutable $vencimento,
        public readonly \DateTimeImmutable $recebimento,
        public readonly int $valorDividaCentavos,
        public readonly int $valorJurosCentavos,
        public readonly int $valorMultaCentavos,
        public readonly int $valorHonorariosCentavos,
        public readonly ?AcordoDoRelatorio $acordo,
        public readonly array $linhas,
    ) {
    }

    /**
     * Juros + multa, em centavos — o balde `valorEncargos` do `Pagamento`, que não os separa.
     *
     * Juros e multa chegam SEPARADOS nesta classe porque a obrigação criada em R1 nasce liquidada, e
     * `Obrigacao::liquidar()` recebe os quatro encargos um a um. Fundi-los na leitura obrigaria a
     * adivinhar a divisão depois — e adivinhar encargo é como o sistema erra dinheiro.
     */
    public function valorEncargosCentavos(): int
    {
        return $this->valorJurosCentavos + $this->valorMultaCentavos;
    }

    /** Bruto recebido do devedor, em centavos — dívida + encargos + honorários. */
    public function totalRecebidoCentavos(): int
    {
        return $this->valorDividaCentavos + $this->valorEncargosCentavos() + $this->valorHonorariosCentavos;
    }

    /** O que abate o saldo do credor, em centavos — é o que vira Σ das alocações do pagamento. */
    public function recuperadoDividaCentavos(): int
    {
        return $this->valorDividaCentavos + $this->valorEncargosCentavos();
    }

    /**
     * Recebimento SEM principal: só honorário e/ou juros/multa, nenhuma taxa de condomínio.
     *
     * Medido em 03/08: **37 na TOP LIFE I** (R$ 11.179,36) e nenhum entre os aceitos da II (o único
     * candidato de lá, o NN `60082`, é rejeitado antes por líquido zero). Destes, **10 têm exigível
     * ZERO** (só a classe `1.15`, R$ 2.618,18): a obrigação criada por R1 nasce valendo R$ 0,00 e a
     * alocação que a acompanha também. Os outros 27 têm juros/multa como exigível, a alocação bate e
     * eles quitam certo.
     *
     * ⚠️ É a pendência que a spec §9 declara ABERTA: o dono precisa medir se esse boleto é acessório de
     * um de taxa antes de decidir se aceita, e ainda não bateu o martelo. Até lá o importador NÃO
     * decide sozinho — ele conta e o comando imprime, para o número estar na frente de quem confirma.
     * Nenhum centavo se perde em nenhuma das duas hipóteses: o total recebido fecha ao centavo com a
     * contabilidade de qualquer jeito (medido). O que está em jogo é a FORMA do que entra.
     */
    public function semPrincipal(): bool
    {
        return $this->valorDividaCentavos === 0;
    }

    /**
     * A descrição que vai para a obrigação.
     *
     * Como parcela de acordo ela diz o que a linha realmente é — "Acordo 348 - Parc. 1/40" —, e não
     * "Taxa 03/2026", que era a fonte da confusão: um boleto 100% honorário descrito como taxa.
     */
    public function descricao(bool $comoParcelaDeAcordo = false): string
    {
        if ($comoParcelaDeAcordo && $this->acordo !== null) {
            return sprintf(
                'Acordo %d - Parc. %d/%d (NN %s)',
                $this->acordo->numero,
                $this->acordo->parcelaIndice,
                $this->acordo->parcelaTotal,
                $this->nn,
            );
        }

        return sprintf('Taxa %s (NN %s)', $this->competencia, $this->nn);
    }
}
