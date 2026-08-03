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

    public function descricao(): string
    {
        return sprintf('Taxa %s (NN %s)', $this->competencia, $this->nn);
    }
}
