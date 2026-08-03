<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Resultado da importação de Receitas (spec `docs/specs/cobranca-importar-receitas.md`). A PRÉVIA e a
 * CONFIRMAÇÃO produzem esta MESMA estrutura — é isso que permite conferir a projeção contra o efeito,
 * campo a campo, e não por amostra.
 *
 * ⚠️ A prévia só é confiável se carregar ESTADO INTRA-EXECUÇÃO: dois recebimentos do mesmo objeto não
 * podem contar "objeto criado" duas vezes, e o segundo recebimento do mesmo NN tem de ver o primeiro.
 * Uma prévia que só consulta o banco mente — já mentiu duas vezes nesta frente (ver
 * `feedback_previa_precisa_de_estado`).
 */
final class ResultadoImportacaoReceitas
{
    /**
     * @param list<string>         $pagamentosCriados      NN de cada recebimento que entra
     * @param list<string>         $jaImportados           NN já presente com a mesma data (idempotência)
     * @param list<string>         $obrigacoesCriadas      NN cuja obrigação NÃO existia e nasceu paga (R1)
     * @param list<string>         $obrigacoesExistentes   NN que pousou em obrigação já conhecida
     * @param list<LinhaRejeitada> $rejeitadas
     */
    public function __construct(
        public readonly array $pagamentosCriados,
        public readonly array $jaImportados,
        public readonly array $obrigacoesCriadas,
        public readonly array $obrigacoesExistentes,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
        public readonly int $emAberto,
        public readonly int $objetosCriados,
        public readonly int $pessoasCriadas,
        public readonly int $casosCriados,
        public readonly int $acordosCriados,
        public readonly int $totalRecebidoCentavos,
        public readonly int $honorariosCentavos,
        /**
         * Σ dos encargos recebidos (classes `1.4` juros e `1.5` multa), em centavos — o mesmo valor que
         * vai para `Pagamento::valorEncargos`.
         *
         * Existe para a CONFERÊNCIA: o rodapé do relatório da contábil imprime o total recebido por
         * classe de conta, então os três baldes conferem um a um contra ele, e não só o total. Sem este
         * campo o resumo mostrava dois números e "abatimento de dívida" agregava principal + encargos —
         * bate no total e esconde uma troca entre baldes.
         */
        public readonly int $encargosCentavos = 0,
        /**
         * NNs cujo recebimento não tem principal nenhum — só honorário e/ou juros/multa. A obrigação
         * criada por R1 nasce com `valorOriginal = 0`, e nas que também não têm encargo o exigível é
         * zero e a alocação vale R$ 0,00.
         *
         * ⚠️ Existe porque a spec §9 declara esta decisão ABERTA e do dono. O importador conta e o
         * comando imprime; ninguém decide por ele. Medido em 03/08: 37 na TOP LIFE I (R$ 11.179,36,
         * dos quais 10 com exigível zero) e nenhum entre os aceitos da II.
         *
         * @var list<string>
         */
        public readonly array $semPrincipal = [],
        public readonly int $semPrincipalCentavos = 0,
        /**
         * NN de cada recebimento que entra como PARCELA de acordo (etapa 3, coluna J). Medido em
         * 03/08: 187 dos 2.078 — 160 na TOP LIFE I e 27 na II.
         *
         * @var list<string>
         */
        public readonly array $parcelasDeAcordo = [],
        /** Acordos que terminam com todas as parcelas pagas e viram `Cumprido` (decisão A2). */
        public readonly int $acordosCumpridos = 0,
        /**
         * Acordos que ficam com parcelas faltando. O comando os imprime porque a fonte das futuras
         * pode não existir (decisão B1: nada é sintetizado) — medido em 03/08: 31 incompletos, dos
         * quais 4 sem nenhuma fonte (acordos 212, 230, 237 e 280, somando 71 parcelas).
         *
         * @var list<array{numero: int, pagas: int, total: int}>
         */
        public readonly array $acordosIncompletos = [],
        /** Acordos rompidos/cancelados que a importação devolveu a `Ativo` — D6. */
        public readonly int $acordosReativados = 0,
        /**
         * ⚠️ O efeito colateral de D6 que a spec-cancelar §3.2 manda vigiar: a reativação devolve as
         * obrigações originais ao estado "substituída", e elas saem do exigível. Se alguma delas tiver
         * recebido pagamento enquanto o acordo estava rompido, esse dinheiro **para de abater o saldo**
         * — o devedor passa a ser cobrado por algo que já pagou.
         *
         * O importador NÃO corrige: decidir para onde vai dinheiro de terceiro não é dele. Ele lista.
         *
         * @var list<string>
         */
        public readonly array $reativacoesComDinheiroParado = [],
        /**
         * Obrigações que MUDAM de acordo nesta importação. Medido em 03/08: nenhum NN aparece em dois
         * acordos, então a lista vem vazia — mas mover uma parcela entre acordos altera a composição de
         * saldo dos dois, e isso não pode acontecer sem alguém ver.
         *
         * @var list<string>
         */
        public readonly array $trocasDeAcordo = [],
    ) {
    }

    /**
     * O que abate dívida, em centavos — o bruto menos a parte que é honorário do escritório.
     *
     * ⚠️ INCLUI os encargos: é a soma do que sai do bolso do devedor para quitar o boleto, honorário à
     * parte. Para conferir o PRINCIPAL puro contra a planilha, use `principalCentavos()`.
     */
    public function recuperadoDividaCentavos(): int
    {
        return $this->totalRecebidoCentavos - $this->honorariosCentavos;
    }

    /** Só o principal (taxa de condomínio e afins), já líquido dos descontos da classe `1.6`. */
    public function principalCentavos(): int
    {
        return $this->totalRecebidoCentavos - $this->honorariosCentavos - $this->encargosCentavos;
    }
}
