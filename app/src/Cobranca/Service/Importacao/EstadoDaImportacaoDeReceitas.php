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
    private int $totalRecebido = 0;
    private int $honorarios = 0;

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

    public function contarAcordoCriado(): void
    {
        ++$this->acordosCriados;
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
        $this->totalRecebido += $receita->totalRecebidoCentavos();
        $this->honorarios += $receita->valorHonorariosCentavos;
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
            totalRecebidoCentavos: $this->totalRecebido,
            honorariosCentavos: $this->honorarios,
        );
    }
}
