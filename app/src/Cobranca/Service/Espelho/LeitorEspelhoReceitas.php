<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\BlocoRelatorio;
use App\Cobranca\Enum\FormaTotalizador;
use App\Cobranca\Enum\TipoRelatorioContabil;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lê o relatório de RECEITAS DETALHADAS por unidade
 * (SPEC docs/specs/cobranca-espelho-quatro-relatorios.md §3.3).
 *
 * Aba única, cabeçalho de 10 colunas na linha 7, e o arquivo maior das três carteiras: **21.304 linhas
 * de dado** na TOP LIFE I.
 *
 * 🔑 **É o relatório do que ENTROU, não do que se deve.** Traz `Valor` e `Valor recebido` lado a lado
 * — a segunda coluna existe porque um recebimento pode ser parcial. Não tem coluna de encargo: juros
 * e multa recebidos vêm como CLASSE DE CONTA própria (`1.4 - Juros`, `1.5 - Multas`), em linha
 * separada, exatamente como na inadimplência.
 */
final class LeitorEspelhoReceitas implements LeitorDeEspelho
{
    use LeCelulasDoEspelho;

    /** Incrementar quando ESTA leitura mudar — não afeta os lotes dos outros três layouts. */
    public const VERSAO = 1;

    private const CABECALHO_ESPERADO = [
        'Unidade',
        'Sacado',
        'NN',
        'Classe de Conta',
        'Competência',
        'Vencimento',
        'Recebimento',
        'Valor (R$)',
        'Valor recebido (R$)',
        'Informações do acordo',
    ];

    private const COL_UNIDADE = 0;
    private const COL_SACADO = 1;
    private const COL_NN = 2;
    private const COL_CLASSE = 3;
    private const COL_COMPETENCIA = 4;
    private const COL_VENCIMENTO = 5;
    private const COL_RECEBIMENTO = 6;
    private const COL_VALOR = 7;
    private const COL_VALOR_RECEBIDO = 8;
    private const COL_ACORDO = 9;

    public function tipo(): TipoRelatorioContabil
    {
        return TipoRelatorioContabil::Receitas;
    }

    public function versao(): int
    {
        return self::VERSAO;
    }

    public function ler(string $caminhoArquivo): ArquivoEspelhado
    {
        $hash = hash_file('sha256', $caminhoArquivo);

        if ($hash === false) {
            throw new ArquivoForaDoLayoutException(sprintf('Não foi possível ler o arquivo "%s".', $caminhoArquivo));
        }

        $reader = IOFactory::createReaderForFile($caminhoArquivo);
        $reader->setReadDataOnly(true);
        $planilha = $reader->load($caminhoArquivo);

        /** @var list<list<mixed>> $grade */
        $grade = $planilha->getActiveSheet()->toArray(null, true, false, false);

        // INV-R4: sem desconectar, a memória acumula entre arquivos.
        $planilha->disconnectWorksheets();
        unset($planilha, $reader);

        $indiceCabecalho = $this->localizarCabecalho($grade);

        return new ArquivoEspelhado(
            arquivoNome: basename($caminhoArquivo),
            arquivoHash: $hash,
            emitidoEm: $this->lerEmissao($grade),
            // As receitas não declaram data de corte de cálculo: são fatos passados, não projeção até
            // uma data. Inventar uma daria à calibração âncora que a contabilidade não deu (INV-E3).
            dadosAte: null,
            configDeclarada: null,
            unidadesDeclaradas: null,
            linhasTotal: count($grade),
            linhas: $this->classificar($grade, $indiceCabecalho),
            totalizadores: $this->extrairTotalizadores($grade, $indiceCabecalho),
        );
    }

    /**
     * O portão das receitas: a soma das linhas de dado tem que bater com o `Total de receitas` do
     * rodapé, **nas duas colunas de dinheiro**.
     *
     * Medido nos 3 arquivos de 10/08/2026, e fecha ao centavo em todos:
     *
     * | carteira | linhas | Σ valor | Σ recebido |
     * |---|---:|---:|---:|
     * | TOP LIFE I | 21.304 | 1.449.866,58 | 1.317.805,47 |
     * | TOP LIFE II | 1.978 | 160.035,68 | 144.272,71 |
     * | AMLI BR 060 | 743 | 61.822,70 | 56.693,54 |
     *
     * ⚠️ **Conferir só a coluna `Valor` deixaria passar erro na `Valor recebido`** — e é a segunda que
     * diz quanto dinheiro entrou de verdade. Medição vale pelo recorte que cobre.
     */
    public function exigirReconciliacaoInterna(ArquivoEspelhado $espelhado): void
    {
        $rodape = null;

        foreach ($espelhado->totalizadores as $totalizador) {
            if (str_starts_with(mb_strtolower($totalizador->rotulo), 'total de receitas')) {
                $rodape = $totalizador;
            }
        }

        if ($rodape === null) {
            throw new ReconciliacaoInternaFalhouException(sprintf(
                'A planilha "%s" não traz a linha "Total de receitas" no rodapé — não há contra o que '
                . 'conferir. Nada foi gravado.',
                $espelhado->arquivoNome,
            ));
        }

        $somaValor = 0;
        $somaRecebido = 0;

        foreach ($espelhado->linhasDeDados() as $linha) {
            $somaValor += $linha->valor ?? 0;
            $somaRecebido += $linha->valorRecebido ?? 0;
        }

        $declaradoValor = $rodape->valor ?? 0;
        $declaradoRecebido = $rodape->valorRecebido ?? 0;

        if ($somaValor === $declaradoValor && $somaRecebido === $declaradoRecebido) {
            return;
        }

        throw new ReconciliacaoInternaFalhouException(sprintf(
            'Em "%s" a soma das linhas não bate com o "Total de receitas" da própria planilha. '
            . 'Valor: somado %d, declarado %d. Valor recebido: somado %d, declarado %d. Nada foi gravado.',
            $espelhado->arquivoNome,
            $somaValor,
            $declaradoValor,
            $somaRecebido,
            $declaradoRecebido,
        ));
    }

    /**
     * @param list<list<mixed>> $grade
     */
    private function localizarCabecalho(array $grade): int
    {
        foreach ($grade as $indice => $linha) {
            $textos = array_map(
                fn (mixed $c): string => $this->texto($c) ?? '',
                array_slice($linha, 0, count(self::CABECALHO_ESPERADO)),
            );

            if ($textos === self::CABECALHO_ESPERADO) {
                return $indice;
            }
        }

        throw new ArquivoForaDoLayoutException(sprintf(
            'Cabeçalho de receitas não encontrado. Esperado, em uma única linha: %s.',
            implode(' · ', self::CABECALHO_ESPERADO),
        ));
    }

    /**
     * INV-Q5: toda linha cai em exatamente um balde, e a soma tem que dar o total de linhas.
     *
     * O discriminador de dado é o **vencimento válido**, como nos outros leitores. As linhas do
     * totalizador por classe têm rótulo em A e números em B/C, sem vencimento — caem em `Totalizador`.
     *
     * @param list<list<mixed>> $grade
     *
     * @return list<LinhaEspelhada>
     */
    private function classificar(array $grade, int $indiceCabecalho): array
    {
        $linhas = [];
        $viuDado = false;

        foreach ($grade as $indice => $celulas) {
            $numeroLinha = $indice + 1;

            $bloco = match (true) {
                $this->linhaVazia($celulas) => BlocoRelatorio::Branca,
                $indice <= $indiceCabecalho => BlocoRelatorio::Cabecalho,
                $this->data($celulas[self::COL_VENCIMENTO] ?? null) !== null => BlocoRelatorio::Dados,
                $this->ehTotalizador($celulas) => BlocoRelatorio::Totalizador,
                default => BlocoRelatorio::Rodape,
            };

            if ($bloco === BlocoRelatorio::Dados) {
                $viuDado = true;
            }

            if ($bloco === BlocoRelatorio::Rodape && !$viuDado) {
                $bloco = BlocoRelatorio::Cabecalho;
            }

            $ehDado = $bloco === BlocoRelatorio::Dados;

            $linhas[] = new LinhaEspelhada(
                numeroLinha: $numeroLinha,
                bloco: $bloco,
                unidade: $ehDado ? $this->texto($celulas[self::COL_UNIDADE] ?? null) : null,
                sacado: $ehDado ? $this->texto($celulas[self::COL_SACADO] ?? null) : null,
                nn: $ehDado ? $this->texto($celulas[self::COL_NN] ?? null) : null,
                classe: $ehDado ? $this->texto($celulas[self::COL_CLASSE] ?? null) : null,
                competencia: $ehDado ? $this->texto($celulas[self::COL_COMPETENCIA] ?? null) : null,
                vencimento: $ehDado ? $this->data($celulas[self::COL_VENCIMENTO] ?? null) : null,
                atraso: null,
                valor: $ehDado ? $this->centavos($celulas[self::COL_VALOR] ?? null) : null,
                juros: null,
                multa: null,
                correcao: null,
                honorarios: null,
                total: null,
                acordoTexto: $ehDado ? $this->texto($celulas[self::COL_ACORDO] ?? null) : null,
                recebimento: $ehDado ? $this->texto($celulas[self::COL_RECEBIMENTO] ?? null) : null,
                bruto: $this->bruto($celulas),
                liquidacao: $ehDado ? $this->data($celulas[self::COL_RECEBIMENTO] ?? null) : null,
                valorRecebido: $ehDado ? $this->centavos($celulas[self::COL_VALOR_RECEBIDO] ?? null) : null,
            );
        }

        return $linhas;
    }

    /**
     * O rodapé somado: rótulo em A (classe de conta ou `Total de receitas`) e dois números em B e C.
     *
     * @param list<mixed> $celulas
     */
    private function ehTotalizador(array $celulas): bool
    {
        return $this->texto($celulas[0] ?? null) !== null
            && $this->centavos($celulas[1] ?? null) !== null;
    }

    /**
     * @param list<list<mixed>> $grade
     *
     * @return list<TotalizadorEspelhado>
     */
    private function extrairTotalizadores(array $grade, int $indiceCabecalho): array
    {
        $totalizadores = [];

        foreach ($grade as $indice => $celulas) {
            if ($indice <= $indiceCabecalho
                || $this->data($celulas[self::COL_VENCIMENTO] ?? null) !== null
                || !$this->ehTotalizador($celulas)
            ) {
                continue;
            }

            $totalizadores[] = new TotalizadorEspelhado(
                numeroLinha: $indice + 1,
                forma: FormaTotalizador::Estreita,
                rotulo: $this->texto($celulas[0] ?? null) ?? '',
                valor: $this->centavos($celulas[1] ?? null),
                juros: null,
                multa: null,
                correcao: null,
                honorarios: null,
                total: null,
                valorRecebido: $this->centavos($celulas[2] ?? null),
            );
        }

        return $totalizadores;
    }

    /** @param list<list<mixed>> $grade */
    private function lerEmissao(array $grade): ?\DateTimeImmutable
    {
        foreach (array_reverse($grade) as $celulas) {
            $texto = $this->texto($celulas[0] ?? null);

            if ($texto !== null && preg_match('/^Emiss[ãa]o:\s*(\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2})/u', $texto, $m) === 1) {
                $data = \DateTimeImmutable::createFromFormat('!d/m/Y H:i', $m[1]);

                return $data === false ? null : $data;
            }
        }

        return null;
    }
}
