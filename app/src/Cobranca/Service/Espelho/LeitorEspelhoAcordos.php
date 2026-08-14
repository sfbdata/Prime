<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\BlocoRelatorio;
use App\Cobranca\Enum\FormaTotalizador;
use App\Cobranca\Enum\TabelaDoAcordo;
use App\Cobranca\Enum\TipoRelatorioContabil;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lê o relatório de ACORDOS DETALHADOS da contabilidade
 * (SPEC docs/specs/cobranca-espelho-quatro-relatorios.md §3.2).
 *
 * É o layout mais diferente dos quatro: **uma aba por acordo** (392 abas nas três carteiras, medido em
 * 10/08/2026), cada uma com um cabeçalho de pares `Rótulo: valor` e **duas tabelas** de significados
 * distintos ({@see TabelaDoAcordo}).
 *
 * 🔴 **INV-Q1 — este relatório NÃO publica encargo.** Não há coluna de juros, multa, correção ou
 * honorário em nenhuma das duas tabelas. Quem espera daqui o honorário de uma parcela de acordo vai
 * embora de mãos vazias: onde a contabilidade publica encargo de parcela é na **inadimplência**. O que
 * este relatório responde é outra pergunta, e é a que falta — *o sistema tem os mesmos acordos, as
 * mesmas parcelas, os mesmos vencimentos e os mesmos valores acordados que ela?*
 *
 * ⚠️ **Dois arquivos por carteira**, não um: `_EM_ANDAMENTO` e `_LIQUIDADO` são o mesmo tipo emitido
 * com recortes diferentes. `_CANCELADO` é recusado antes daqui, pelo {@see \App\Cobranca\Service\Importacao\RecorteEsperado::acordos()}.
 *
 * ⚠️ **As linhas da primeira tabela vêm espaçadas de duas em duas** (L14, L16, L18…), com linha em
 * branco no meio. Um leitor que pare na primeira linha vazia lê UMA conta e reporta sucesso — por isso
 * o fim de uma tabela é o título da seguinte ou o rodapé, nunca a primeira linha em branco.
 */
final class LeitorEspelhoAcordos implements LeitorDeEspelho
{
    use LeCelulasDoEspelho;

    /** Incrementar quando ESTA leitura mudar — não afeta os lotes dos outros três layouts. */
    public const VERSAO = 1;

    private const CABECALHO_CONTAS_ORIGINAIS = [
        'Nosso Número',
        'Classe de Conta',
        'Competência',
        'Vencimento',
        'Valor original (R$)',
        'Detalhamento',
    ];

    private const CABECALHO_PARCELAS = [
        'Nosso Número',
        'Classe de Conta',
        'Parcela',
        'Competência',
        'Vencimento',
        'Liquidação',
        'Valor acordado (R$)',
        'Valor liquidado (R$)',
    ];

    private const TITULO_CONTAS_ORIGINAIS = 'Relação das contas originais';
    private const TITULO_PARCELAS = 'Parcelas das contas geradas pelo';

    public function tipo(): TipoRelatorioContabil
    {
        return TipoRelatorioContabil::Acordos;
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

        $nomesDasAbas = $planilha->getSheetNames();

        if ($nomesDasAbas === []) {
            throw new ArquivoForaDoLayoutException(sprintf(
                'O arquivo "%s" não tem aba nenhuma — o relatório de acordos tem uma por acordo.',
                basename($caminhoArquivo),
            ));
        }

        $linhas = [];
        $totalizadores = [];
        $linhasTotal = 0;
        $emitidoEm = null;

        foreach ($nomesDasAbas as $nomeDaAba) {
            /** @var list<list<mixed>> $grade */
            $grade = $planilha->getSheetByName($nomeDaAba)?->toArray(null, true, false, false) ?? [];

            $linhasTotal += count($grade);
            $emitidoEm ??= $this->lerEmissao($grade);

            foreach ($this->classificarAba($grade, $nomeDaAba) as $linha) {
                $linhas[] = $linha;
            }

            $totalizador = $this->totalizadorDaAba($grade, $nomeDaAba);

            if ($totalizador !== null) {
                $totalizadores[] = $totalizador;
            }
        }

        // INV-R4: o PhpSpreadsheet mantém referências circulares que o coletor de lixo não desfaz.
        // Aqui pesa mais do que na inadimplência — são 260 abas num arquivo só.
        $planilha->disconnectWorksheets();
        unset($planilha, $reader);

        if ($totalizadores === []) {
            throw new ArquivoForaDoLayoutException(sprintf(
                'Nenhuma aba de "%s" traz "Acordo de número" e "Valor final acordado" — isto não parece '
                . 'um relatório de acordos detalhados.',
                basename($caminhoArquivo),
            ));
        }

        return new ArquivoEspelhado(
            arquivoNome: basename($caminhoArquivo),
            arquivoHash: $hash,
            emitidoEm: $emitidoEm,
            // O relatório de acordos não declara data de corte: ele é um retrato do cadastro, não um
            // cálculo até uma data. Fingir uma (a da emissão, por exemplo) daria à calibração uma
            // âncora que a contabilidade não deu. Ver INV-E3 do espelho.
            dadosAte: null,
            configDeclarada: null,
            unidadesDeclaradas: null,
            linhasTotal: $linhasTotal,
            linhas: $linhas,
            totalizadores: $totalizadores,
        );
    }

    /**
     * INV-Q8 — o portão fecha POR ABA, com tolerância medida.
     *
     * Não existe total geral no arquivo. O que existe é, por aba, o `Valor final acordado` do
     * cabeçalho contra a soma da coluna `Valor acordado` das parcelas. Medido nos 6 arquivos das 3
     * carteiras: **381 de 392 abas fecham exatamente**; as 11 restantes divergem entre −1 e −5
     * centavos, **sempre para menos**, e sempre com `|diferença| ≤ número de LINHAS de parcela`.
     *
     * A régua é por LINHA e não por parcela porque o `Acordo n105` tem **1 parcela, 38 linhas e
     * d = −2**: é a contabilidade arredondando cada linha para baixo ao ratear.
     *
     * ⚠️ Aba que usa a tolerância é **contada**, e o total vai para a saída do comando. Tolerância
     * silenciosa é o descarte silencioso do INV-Q6 com outro nome.
     */
    public function exigirReconciliacaoInterna(ArquivoEspelhado $espelhado): void
    {
        // 🔴 Antes de qualquer soma: aba sem `Valor final acordado` legível não tem contra o que
        // fechar, e o laço abaixo — que percorre os TOTALIZADORES — jamais a veria. Recusar aqui é o
        // que impede o descarte silencioso de renascer (achado 9).
        $semDeclaracao = $this->abasSemValorDeclarado($espelhado);

        if ($semDeclaracao !== []) {
            throw new ReconciliacaoInternaFalhouException(sprintf(
                'Em "%s", %d aba(s) não trazem "Valor final acordado" legível — não há contra o que '
                . "conferir as parcelas delas, e passar adiante seria gravá-las sem conferência nenhuma.\n  %s",
                $espelhado->arquivoNome,
                count($semDeclaracao),
                implode(', ', array_slice($semDeclaracao, 0, 10)),
            ));
        }

        $porAba = $this->somarParcelasPorAba($espelhado);

        $forasDeFaixa = [];

        foreach ($espelhado->totalizadores as $totalizador) {
            $aba = $totalizador->rotulo;
            $declarado = $totalizador->valor;

            if ($declarado === null) {
                continue;
            }

            $somado = $porAba[$aba]['soma'] ?? 0;
            $linhasDeParcela = $porAba[$aba]['linhas'] ?? 0;
            $diferenca = $somado - $declarado;

            if ($diferenca === 0) {
                continue;
            }

            // Só para MENOS, e no máximo um centavo por linha rateada.
            if ($diferenca < 0 && -$diferenca <= $linhasDeParcela) {
                continue;
            }

            $forasDeFaixa[] = sprintf(
                '%s: parcelas somam %d, o acordo declara %d (diferença de %+d centavos em %d linha[s])',
                $aba,
                $somado,
                $declarado,
                $diferenca,
                $linhasDeParcela,
            );
        }

        if ($forasDeFaixa !== []) {
            throw new ReconciliacaoInternaFalhouException(sprintf(
                "Em \"%s\", %d aba(s) não fecham entre a soma das parcelas e o \"Valor final acordado\" "
                . "do próprio acordo, além do arredondamento de rateio. Nada foi gravado.\n  %s",
                $espelhado->arquivoNome,
                count($forasDeFaixa),
                implode("\n  ", array_slice($forasDeFaixa, 0, 10)),
            ));
        }
    }

    /**
     * O que a tolerância consumiu — **abas E centavos**, para o comando imprimir.
     *
     * ⚠️ Separado do portão de propósito: o portão decide passa/não passa, isto diz **quanto** da
     * passagem foi por tolerância. *"Tolerância visível é segura; silenciosa é que não"* — mesma regra
     * do INV-CB3, que também descarta linha e conta o descarte num contador próprio.
     *
     * 🔑 **Os centavos importam tanto quanto as abas.** A régua derivada do rateio abre um envelope de
     * até um centavo por linha de parcela: nos 6 arquivos reais isso daria **R$ 87,67** de folga, e o
     * que de fato se usou foi **R$ 0,27** em 11 abas. Reportar só "11 abas" esconderia se um dia a
     * folga passasse a ser consumida de verdade — e é a diferença entre os dois números que avisa.
     *
     * @return array{abas: int, centavos: int}
     */
    public function toleranciaConsumida(ArquivoEspelhado $espelhado): array
    {
        $porAba = $this->somarParcelasPorAba($espelhado);
        $abas = 0;
        $centavos = 0;

        foreach ($espelhado->totalizadores as $totalizador) {
            $declarado = $totalizador->valor;

            if ($declarado === null) {
                continue;
            }

            $diferenca = ($porAba[$totalizador->rotulo]['soma'] ?? 0) - $declarado;

            if ($diferenca !== 0) {
                ++$abas;
                $centavos += abs($diferenca);
            }
        }

        return ['abas' => $abas, 'centavos' => $centavos];
    }

    /**
     * As abas cujo `Valor final acordado` não foi encontrado ou não converteu.
     *
     * 🔴 Achado 9 da revisão: sem isto, uma aba nessas condições **não entrava nem em conferência nem
     * em recusa** — o portão só itera os totalizadores, então ela sumia. É o descarte silencioso do
     * INV-Q6 renascido num lugar novo, que a própria spec chama de "o defeito mais provável daqui".
     * Medido: **0 abas nessa situação nos 6 arquivos reais** — furo latente, não defeito vivo.
     *
     * @return list<string>
     */
    public function abasSemValorDeclarado(ArquivoEspelhado $espelhado): array
    {
        $comTotalizador = [];

        foreach ($espelhado->totalizadores as $totalizador) {
            $comTotalizador[$totalizador->rotulo] = true;
        }

        $sem = [];

        foreach ($espelhado->linhas as $linha) {
            // ⚠️ Duas condições SEPARADAS de propósito. `!isset($a, $b)` é verdadeiro quando
            // **qualquer um** dos dois falta — escrito assim, acusava toda aba que tinha totalizador,
            // porque `$sem[$aba]` ainda não existia na primeira passagem.
            if ($linha->aba === null || isset($comTotalizador[$linha->aba])) {
                continue;
            }

            $sem[$linha->aba] = true;
        }

        return array_keys($sem);
    }

    /**
     * @return array<string, array{soma: int, linhas: int}>
     */
    private function somarParcelasPorAba(ArquivoEspelhado $espelhado): array
    {
        $porAba = [];

        foreach ($espelhado->linhasDeDados() as $linha) {
            if ($linha->tabela !== TabelaDoAcordo::Parcelas->value || $linha->aba === null) {
                continue;
            }

            $porAba[$linha->aba] ??= ['soma' => 0, 'linhas' => 0];
            $porAba[$linha->aba]['soma'] += $linha->valor ?? 0;
            ++$porAba[$linha->aba]['linhas'];
        }

        return $porAba;
    }

    /**
     * Classifica TODA linha da aba em exatamente um balde — INV-Q5, herdado do INV-R3.
     *
     * A máquina de estados é guiada pelos TÍTULOS das tabelas, nunca por linha em branco: as linhas da
     * primeira tabela são espaçadas, e parar na primeira vazia leria uma conta só.
     *
     * @param list<list<mixed>> $grade
     *
     * @return list<LinhaEspelhada>
     */
    private function classificarAba(array $grade, string $nomeDaAba): array
    {
        $linhas = [];
        $tabelaAtual = null;
        $viuDado = false;

        foreach ($grade as $indice => $celulas) {
            $numeroLinha = $indice + 1;
            $primeira = $this->texto($celulas[0] ?? null) ?? '';

            if ($this->linhaVazia($celulas)) {
                $linhas[] = $this->linhaSimples($numeroLinha, BlocoRelatorio::Branca, $celulas, $nomeDaAba, $tabelaAtual);

                continue;
            }

            if (str_starts_with($primeira, self::TITULO_CONTAS_ORIGINAIS)) {
                $tabelaAtual = TabelaDoAcordo::ContasOriginais;
                $linhas[] = $this->linhaSimples($numeroLinha, BlocoRelatorio::Cabecalho, $celulas, $nomeDaAba, null);

                continue;
            }

            if (str_starts_with($primeira, self::TITULO_PARCELAS)) {
                $tabelaAtual = TabelaDoAcordo::Parcelas;
                $linhas[] = $this->linhaSimples($numeroLinha, BlocoRelatorio::Cabecalho, $celulas, $nomeDaAba, null);

                continue;
            }

            // O rodapé encerra a tabela corrente: sem isto, `Filtros:` e `Emissão:` seriam lidos como
            // dado da última tabela aberta.
            if (str_starts_with($primeira, 'Filtros') || str_starts_with($primeira, 'Emissão')) {
                $tabelaAtual = null;
                $linhas[] = $this->linhaSimples($numeroLinha, BlocoRelatorio::Rodape, $celulas, $nomeDaAba, null);

                continue;
            }

            if ($this->ehCabecalhoDeTabela($celulas)) {
                $linhas[] = $this->linhaSimples($numeroLinha, BlocoRelatorio::CabecalhoBloco2, $celulas, $nomeDaAba, null);

                continue;
            }

            if ($tabelaAtual !== null && $this->ehDado($celulas, $tabelaAtual)) {
                $viuDado = true;
                $linhas[] = $this->linhaDeDado($numeroLinha, $celulas, $nomeDaAba, $tabelaAtual);

                continue;
            }

            $linhas[] = $this->linhaSimples(
                $numeroLinha,
                $viuDado ? BlocoRelatorio::Rodape : BlocoRelatorio::Cabecalho,
                $celulas,
                $nomeDaAba,
                null,
            );
        }

        return $linhas;
    }

    /**
     * Linha de dado é a que tem **vencimento válido** — o mesmo discriminador do leitor de
     * inadimplência, e pelo mesmo motivo: é o que impede uma linha de texto de virar dívida.
     *
     * @param list<mixed> $celulas
     */
    private function ehDado(array $celulas, TabelaDoAcordo $tabela): bool
    {
        $colunaDoVencimento = $tabela === TabelaDoAcordo::ContasOriginais ? 3 : 4;

        return $this->data($celulas[$colunaDoVencimento] ?? null) !== null;
    }

    /** @param list<mixed> $celulas */
    private function ehCabecalhoDeTabela(array $celulas): bool
    {
        foreach ([self::CABECALHO_CONTAS_ORIGINAIS, self::CABECALHO_PARCELAS] as $esperado) {
            $textos = array_map(
                fn (mixed $c): string => $this->texto($c) ?? '',
                array_slice($celulas, 0, count($esperado)),
            );

            if ($textos === $esperado) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<mixed> $celulas
     */
    private function linhaDeDado(
        int $numeroLinha,
        array $celulas,
        string $nomeDaAba,
        TabelaDoAcordo $tabela,
    ): LinhaEspelhada {
        if ($tabela === TabelaDoAcordo::ContasOriginais) {
            // NN ¦ Classe ¦ Competência ¦ Vencimento ¦ Valor original ¦ Detalhamento
            return new LinhaEspelhada(
                numeroLinha: $numeroLinha,
                bloco: BlocoRelatorio::Dados,
                unidade: null,
                sacado: null,
                nn: $this->texto($celulas[0] ?? null),
                classe: $this->texto($celulas[1] ?? null),
                competencia: $this->texto($celulas[2] ?? null),
                vencimento: $this->data($celulas[3] ?? null),
                atraso: null,
                valor: $this->centavos($celulas[4] ?? null),
                juros: null,
                multa: null,
                correcao: null,
                honorarios: null,
                total: null,
                acordoTexto: null,
                recebimento: null,
                bruto: $this->bruto($celulas),
                aba: $nomeDaAba,
                tabela: $tabela->value,
            );
        }

        // NN ¦ Classe ¦ Parcela ¦ Competência ¦ Vencimento ¦ Liquidação ¦ Valor acordado ¦ Valor liquidado
        return new LinhaEspelhada(
            numeroLinha: $numeroLinha,
            bloco: BlocoRelatorio::Dados,
            unidade: null,
            sacado: null,
            nn: $this->texto($celulas[0] ?? null),
            classe: $this->texto($celulas[1] ?? null),
            competencia: $this->texto($celulas[3] ?? null),
            vencimento: $this->data($celulas[4] ?? null),
            atraso: null,
            valor: $this->centavos($celulas[6] ?? null),
            juros: null,
            multa: null,
            correcao: null,
            honorarios: null,
            total: null,
            acordoTexto: null,
            recebimento: null,
            bruto: $this->bruto($celulas),
            aba: $nomeDaAba,
            tabela: $tabela->value,
            parcela: $this->texto($celulas[2] ?? null),
            liquidacao: $this->data($celulas[5] ?? null),
            valorRecebido: $this->centavos($celulas[7] ?? null),
        );
    }

    /**
     * @param list<mixed> $celulas
     */
    private function linhaSimples(
        int $numeroLinha,
        BlocoRelatorio $bloco,
        array $celulas,
        string $nomeDaAba,
        ?TabelaDoAcordo $tabela,
    ): LinhaEspelhada {
        return new LinhaEspelhada(
            numeroLinha: $numeroLinha,
            bloco: $bloco,
            unidade: null,
            sacado: null,
            nn: null,
            classe: null,
            competencia: null,
            vencimento: null,
            atraso: null,
            valor: null,
            juros: null,
            multa: null,
            correcao: null,
            honorarios: null,
            total: null,
            acordoTexto: null,
            recebimento: null,
            bruto: $this->bruto($celulas),
            aba: $nomeDaAba,
            tabela: $tabela?->value,
        );
    }

    /**
     * O `Valor final acordado` da aba, guardado como totalizador com o nome da aba no rótulo — é
     * contra ele que o portão fecha.
     *
     * A `Data base` também sai aqui, no rótulo? **Não.** Ela é dado do acordo, não total, e forçá-la
     * num campo de totalizador seria o encaixe indevido que o INV-Q9 proíbe. Ela fica em `$bruto` da
     * linha de cabeçalho, íntegra, até a fatia que for conferir acordo a acordo.
     *
     * @param list<list<mixed>> $grade
     */
    private function totalizadorDaAba(array $grade, string $nomeDaAba): ?TotalizadorEspelhado
    {
        $numeroDaLinha = null;
        $valorFinal = null;

        foreach ($grade as $indice => $celulas) {
            if ($this->valorDoRotulo($celulas, 'Acordo de número') !== null) {
                $numeroDaLinha ??= $indice + 1;
            }

            $declarado = $this->valorDoRotulo($celulas, 'Valor final acordado');

            if ($declarado !== null) {
                $valorFinal = $this->centavos($declarado);
                $numeroDaLinha ??= $indice + 1;

                break;
            }
        }

        if ($valorFinal === null) {
            return null;
        }

        return new TotalizadorEspelhado(
            numeroLinha: $numeroDaLinha ?? 0,
            // Não é nenhuma das duas formas do relatório de inadimplência: o "total" de uma aba de
            // acordo é um par `Rótulo: valor` no cabeçalho, não uma linha de colunas alinhadas.
            forma: FormaTotalizador::Estreita,
            rotulo: $nomeDaAba,
            valor: $valorFinal,
            juros: null,
            multa: null,
            correcao: null,
            honorarios: null,
            total: null,
        );
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
