<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * O ESTADO de uma execução do importador de Receitas — o que já foi criado/decidido nas linhas
 * anteriores do MESMO arquivo.
 *
 * 🔑 Existe porque **prévia que só consulta o banco mente**. O arquivo tem ~2,3 linhas por NN e vários
 * NNs por unidade: sem memória da execução, a prévia contaria "objeto criado" uma vez por recebimento e
 * prometeria um número que a confirmação nunca entregaria — o banco responde "não existe" nas duas
 * consultas porque, na prévia, nada foi criado entre elas.
 *
 * Nesta frente esse defeito já apareceu DUAS vezes, sempre da mesma forma: a prévia consultava o estado
 * inicial e a confirmação via o estado corrente. Por isso a projeção e a persistência compartilham
 * ESTA classe — não há dois caminhos para divergirem.
 *
 * Ver `feedback_previa_precisa_de_estado`.
 */
final class EstadoDaImportacaoDeReceitas
{
    /** @var array<string, true> identificações de objeto já vistas nesta execução */
    private array $objetosVistos = [];
    /** @var array<string, true> identificações cujo caso já existe (ou já foi projetado) */
    private array $casosVistos = [];

    /** @var list<string> */
    private array $pagamentosCriados = [];
    /** @var list<string> */
    private array $jaImportados = [];
    /** @var list<string> */
    private array $obrigacoesCriadas = [];
    /** @var list<string> */
    private array $obrigacoesExistentes = [];

    private int $objetosCriados = 0;
    private int $pessoasCriadas = 0;
    private int $casosCriados = 0;
    private int $acordosCriados = 0;

    /**
     * Números de acordo já vistos nesta execução.
     *
     * 🔑 É a defesa contra o defeito que já apareceu DUAS vezes nesta frente. Um acordo de 8 parcelas
     * pagas ocupa 8 recebimentos do arquivo. Na PRÉVIA o banco responde "não existe" nas 8 consultas
     * (ela não grava), então sem esta memória a prévia prometeria 8 acordos criados onde a confirmação
     * cria 1. Com ela, só o PRIMEIRO encontro conta — e o primeiro encontro vê o mesmo estado de banco
     * nos dois modos.
     *
     * @var array<int, true>
     */
    private array $acordosVistos = [];
    /**
     * Parcelas distintas vistas por acordo, para a régua do `Cumprido` (spec §5.3).
     *
     * @var array<int, array<int, true>>
     */
    private array $parcelasPorAcordo = [];
    /** @var array<int, int> número do acordo => `parcelaTotal` declarado na coluna J */
    private array $totalDeParcelas = [];
    /** @var list<string> NN das parcelas que ficaram ligadas a um acordo */
    private array $parcelasDeAcordo = [];
    private int $acordosReativados = 0;
    /** @var list<string> descrição do dinheiro que a reativação D6 tira do exigível (spec §5.5) */
    private array $reativacoesComDinheiroParado = [];

    private int $totalRecebido = 0;
    private int $honorarios = 0;
    private int $encargos = 0;
    /** @var list<string> */
    private array $semPrincipal = [];
    private int $semPrincipalCentavos = 0;

    /**
     * Registra o objeto/caso desta linha, contando criação **uma vez por unidade** — não uma vez por
     * recebimento.
     */
    public function projetarObjetoECaso(string $identificacao, bool $objetoExiste, bool $casoExiste): void
    {
        if (!isset($this->objetosVistos[$identificacao])) {
            $this->objetosVistos[$identificacao] = true;
            if (!$objetoExiste) {
                ++$this->objetosCriados;
            }
        }

        if (!isset($this->casosVistos[$identificacao])) {
            $this->casosVistos[$identificacao] = true;
            if (!$casoExiste) {
                // A pessoa nasce JUNTO do caso — sempre, e só aqui. Contá-la em outro lugar (por
                // exemplo, só no ramo de escrita da confirmação) faria a prévia prometer zero pessoas e
                // a confirmação criar N: a divergência clássica que esta classe existe para impedir.
                ++$this->casosCriados;
                ++$this->pessoasCriadas;
            }
        }
    }

    /**
     * Registra o acordo desta linha, contando a criação **uma vez por acordo** — não uma por parcela.
     *
     * `$reativado` e `$dinheiroParado` só são consumidos no PRIMEIRO encontro, e por isso valem o mesmo
     * na prévia e na confirmação: na prévia o acordo continua rompido nas linhas seguintes (nada foi
     * gravado), na confirmação ele já está ativo — mas as duas só olham a primeira.
     *
     * @param list<string> $dinheiroParado descrições das obrigações com alocação que a reativação tira
     *                                     do exigível (spec §5.5)
     */
    public function projetarAcordo(
        AcordoDoRelatorio $acordo,
        bool $acordoExiste,
        bool $reativado,
        array $dinheiroParado = [],
    ): void {
        if (!isset($this->acordosVistos[$acordo->numero])) {
            $this->acordosVistos[$acordo->numero] = true;
            if (!$acordoExiste) {
                ++$this->acordosCriados;
            }
            if ($reativado) {
                ++$this->acordosReativados;
                foreach ($dinheiroParado as $descricao) {
                    $this->reativacoesComDinheiroParado[] = $descricao;
                }
            }
        }

        // Índices DISTINTOS: o mesmo índice não pode contar duas vezes e empurrar o acordo para
        // `Cumprido` sem que todas as parcelas estejam lá. Medido: nenhuma parcela tem dois NNs — mas
        // a régua não pode depender de uma propriedade da fonte de hoje.
        $this->parcelasPorAcordo[$acordo->numero][$acordo->parcelaIndice] = true;
        $this->totalDeParcelas[$acordo->numero] = $acordo->parcelaTotal;
    }

    /**
     * Números dos acordos que terminam a execução com TODAS as parcelas pagas — os que ficam
     * `Cumprido` (decisão A2).
     *
     * Conta só o que ESTE arquivo traz, de propósito: uma parcela paga numa importação anterior não
     * entra na conta, então o acordo fica `Ativo` quando deveria ser `Cumprido`. É o lado certo de
     * errar — marcar `Cumprido` um acordo que ainda deve seria subcobrança silenciosa.
     *
     * @return list<int>
     */
    public function numerosCumpridos(): array
    {
        $cumpridos = [];
        foreach ($this->parcelasPorAcordo as $numero => $indices) {
            $total = $this->totalDeParcelas[$numero] ?? 0;
            if ($total > 0 && count($indices) >= $total) {
                $cumpridos[] = $numero;
            }
        }

        return $cumpridos;
    }

    /**
     * Acordos que ficam com parcelas faltando — o comando os imprime para o dono pedir a fonte à
     * contábil (decisão B1: nada é sintetizado).
     *
     * @return list<array{numero: int, pagas: int, total: int}>
     */
    public function acordosIncompletos(): array
    {
        $incompletos = [];
        foreach ($this->parcelasPorAcordo as $numero => $indices) {
            $total = $this->totalDeParcelas[$numero] ?? 0;
            if ($total > 0 && count($indices) < $total) {
                $incompletos[] = ['numero' => $numero, 'pagas' => count($indices), 'total' => $total];
            }
        }

        return $incompletos;
    }

    public function projetarRecebimento(ReceitaImportavel $receita, bool $obrigacaoExiste, bool $jaImportado): void
    {
        if ($jaImportado) {
            $this->jaImportados[] = $receita->nn;

            return;
        }

        if ($obrigacaoExiste) {
            $this->obrigacoesExistentes[] = $receita->nn;
        } else {
            $this->obrigacoesCriadas[] = $receita->nn;
        }

        $this->pagamentosCriados[] = $receita->nn;

        if ($receita->acordo !== null) {
            $this->parcelasDeAcordo[] = $receita->nn;
        }

        // B1 / spec §9: recebimento SÓ de honorário e/ou juros-multa, sem taxa nenhuma. Contado à
        // parte porque a decisão de aceitá-lo é do dono e ainda está aberta — o importador não decide
        // sozinho, mas também não pode deixar o caso passar sem que ninguém veja. Ver `semPrincipal()`.
        if ($receita->semPrincipal()) {
            $this->semPrincipal[] = $receita->nn;
            $this->semPrincipalCentavos += $receita->totalRecebidoCentavos();
        }

        $this->totalRecebido += $receita->totalRecebidoCentavos();
        $this->honorarios += $receita->valorHonorariosCentavos;
        // O terceiro balde (juros 1.4 + multa 1.5). Ele já ia para `Pagamento::valorEncargos`, mas não
        // saía no resumo — e é exatamente o que a conferência contra a contabilidade precisa: o rodapé
        // da planilha imprime "1.4 - Juros" e "1.5 - Multas" separados, então os três baldes conferem
        // um a um contra o relatório da contábil, e não só o total.
        $this->encargos += $receita->valorEncargosCentavos();
    }

    public function resultado(ResultadoLeituraReceitas $leitura): ResultadoImportacaoReceitas
    {
        return new ResultadoImportacaoReceitas(
            pagamentosCriados: $this->pagamentosCriados,
            jaImportados: $this->jaImportados,
            obrigacoesCriadas: $this->obrigacoesCriadas,
            obrigacoesExistentes: $this->obrigacoesExistentes,
            rejeitadas: $leitura->rejeitadas,
            linhasIgnoradas: $leitura->linhasIgnoradas,
            emAberto: $leitura->emAberto,
            objetosCriados: $this->objetosCriados,
            pessoasCriadas: $this->pessoasCriadas,
            casosCriados: $this->casosCriados,
            acordosCriados: $this->acordosCriados,
            parcelasDeAcordo: $this->parcelasDeAcordo,
            acordosCumpridos: count($this->numerosCumpridos()),
            acordosIncompletos: $this->acordosIncompletos(),
            acordosReativados: $this->acordosReativados,
            reativacoesComDinheiroParado: $this->reativacoesComDinheiroParado,
            totalRecebidoCentavos: $this->totalRecebido,
            honorariosCentavos: $this->honorarios,
            encargosCentavos: $this->encargos,
            semPrincipal: $this->semPrincipal,
            semPrincipalCentavos: $this->semPrincipalCentavos,
        );
    }
}
