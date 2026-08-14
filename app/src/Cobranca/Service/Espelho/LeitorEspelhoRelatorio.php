<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\BlocoRelatorio;
use App\Cobranca\Enum\FormaTotalizador;
use App\Cobranca\Enum\TipoRelatorioContabil;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lê o relatório da contabilidade e devolve o arquivo inteiro, linha por linha, como ele veio
 * (SPEC espelho §4.1).
 *
 * INV-R1: **é deliberadamente independente do `TopLifeInadimplenciaAdapter`.** O adapter interpreta —
 * agrupa por (unidade + NN), soma colunas em baldes, decide o que é principal e descarta G/M/O. Se
 * este leitor reaproveitasse aquela leitura, herdaria cada decisão e cada defeito dela, e não
 * serviria para conferir o adapter, que é exatamente para o que ele existe. A duplicação de
 * mecânica (abrir xlsx, converter decimal) é o preço, e é consciente.
 *
 * INV-R2: **burro quanto ao significado, não quanto à estrutura.** Ele não decide o que é principal
 * nem o que é encargo. Mas precisa saber onde termina o bloco de dados, porque o arquivo tem DUAS
 * tabelas e a segunda usa outro layout de colunas.
 *
 * INV-R3: **nada se perde.** Toda linha do arquivo sai classificada em um dos seis baldes, e
 * `ArquivoEspelhado::contarPorBloco()` tem que somar o total de linhas. Célula que não converte para
 * número vira `null` no campo tipado, mas continua íntegra em `$bruto`.
 */
final class LeitorEspelhoRelatorio implements LeitorDeEspelho
{
    /** Incrementar quando a leitura mudar — entra na chave de idempotência do lote (SPEC §4.2). */
    public const VERSAO = 1;

    public function tipo(): TipoRelatorioContabil
    {
        return TipoRelatorioContabil::Inadimplencia;
    }

    public function versao(): int
    {
        return self::VERSAO;
    }

    /**
     * O portão da inadimplência: a soma das linhas de dado tem que bater com o "Total de
     * inadimplência" que a própria planilha declara, nas SEIS colunas.
     *
     * Medido no TL1 de 12/08: os dois lados dão
     * `44.197.594 · 15.147.395 · 883.952 · 0 · 11.714.735 · 71.943.676`, ao centavo.
     *
     * 📌 Este método veio do `GravarEspelhoRelatorioUseCase` quando o espelho passou a ter quatro
     * leitores (SPEC quatro-relatórios §4.1). O comportamento é o mesmo, linha por linha — o que mudou
     * é o dono: o portão é uma propriedade do LAYOUT, não do gravador.
     */
    public function exigirReconciliacaoInterna(ArquivoEspelhado $espelhado): void
    {
        $rodape = $espelhado->totalGeral();

        if ($rodape === null) {
            throw new ReconciliacaoInternaFalhouException(sprintf(
                'A planilha "%s" não traz linha de total no rodapé — não há contra o que conferir. Nada foi gravado.',
                $espelhado->arquivoNome,
            ));
        }

        $soma = $espelhado->somarDados();
        $declarado = [
            'valor' => $rodape->valor ?? 0,
            'juros' => $rodape->juros ?? 0,
            'multa' => $rodape->multa ?? 0,
            'correcao' => $rodape->correcao ?? 0,
            'honorarios' => $rodape->honorarios ?? 0,
            'total' => $rodape->total ?? 0,
        ];

        if ($soma !== $declarado) {
            throw ReconciliacaoInternaFalhouException::comDivergencia(
                $espelhado->arquivoNome,
                $soma,
                $declarado,
            );
        }
    }

    /** Linha 6 do arquivo, conferida nome a nome. Ver {@see ArquivoForaDoLayoutException}. */
    private const CABECALHO_ESPERADO = [
        'Unidade',
        'Sacado',
        'NN',
        'Classe de conta',
        'Competência',
        'Vencimento',
        'Atraso',
        'Valor (R$)',
        'Juros (R$)',
        'Multa (R$)',
        'Correção (R$)',
        'Honorários (R$)',
        'Total (R$)',
        'Informações do acordo',
        'Recebimento',
    ];

    /** Linha 4133 no TL1 de 12/08: o cabeçalho da segunda tabela, em 7 colunas. */
    private const CABECALHO_BLOCO2 = [
        'Classe de conta',
        'Valor (R$)',
        'Juros (R$)',
        'Multa (R$)',
        'Correção (R$)',
        'Honorários (R$)',
        'Total (R$)',
    ];

    private const COL_UNIDADE = 0;
    private const COL_SACADO = 1;
    private const COL_NN = 2;
    private const COL_CLASSE = 3;
    private const COL_COMPETENCIA = 4;
    private const COL_VENCIMENTO = 5;
    private const COL_ATRASO = 6;
    private const COL_VALOR = 7;
    private const COL_JUROS = 8;
    private const COL_MULTA = 9;
    private const COL_CORRECAO = 10;
    private const COL_HONORARIOS = 11;
    private const COL_TOTAL = 12;
    private const COL_ACORDO = 13;
    private const COL_RECEBIMENTO = 14;

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

        // INV-R4: a planilha PRECISA ser desconectada explicitamente. O PhpSpreadsheet mantém
        // referências circulares entre Spreadsheet, Worksheet e Cell que o coletor de lixo do PHP
        // não desfaz sozinho — sem isto a memória acumula a cada arquivo. Medido: a carga do
        // histórico morria com "Allowed memory size exhausted" no 10º dos 23 relatórios.
        $planilha->disconnectWorksheets();
        unset($planilha, $reader);

        $indiceCabecalho = $this->localizarCabecalho($grade);
        $linhas = $this->classificar($grade, $indiceCabecalho);

        return new ArquivoEspelhado(
            arquivoNome: basename($caminhoArquivo),
            arquivoHash: $hash,
            emitidoEm: $this->lerEmissao($grade),
            dadosAte: $this->lerDadosAte($grade),
            configDeclarada: $this->lerConfigDeclarada($grade),
            unidadesDeclaradas: $this->lerUnidadesDeclaradas($grade),
            linhasTotal: count($grade),
            linhas: $linhas,
            totalizadores: $this->extrairTotalizadores($linhas, $grade),
        );
    }

    /**
     * Acha a linha de cabeçalho conferindo os 15 nomes, e recusa o arquivo se não bater.
     *
     * O adapter de importação só exige que a célula A contenha "Unidade" e depois lê tudo por
     * posição fixa — uma coluna deslocada passaria em silêncio. Aqui não passa.
     *
     * @param list<list<mixed>> $grade
     */
    private function localizarCabecalho(array $grade): int
    {
        foreach ($grade as $indice => $linha) {
            $textos = array_map(fn (mixed $c): string => $this->texto($c) ?? '', array_slice($linha, 0, 15));

            if ($textos === self::CABECALHO_ESPERADO) {
                return $indice;
            }
        }

        throw new ArquivoForaDoLayoutException(sprintf(
            'Cabeçalho não encontrado. Esperado, em uma única linha: %s.',
            implode(' · ', self::CABECALHO_ESPERADO)
        ));
    }

    /**
     * Classifica TODA linha do arquivo em exatamente um dos seis baldes (SPEC §5.4).
     *
     * ⚠️ A regra de precedência escrita na §5.4 da spec ("coluna C preenchida ⟹ dados") **não
     * funciona literalmente**: as duas linhas de cabeçalho também têm a coluna C preenchida (`NN` na
     * linha 6, `Juros (R$)` na 4133) e cairiam em `dados`. O discriminador correto é o **vencimento
     * válido** na coluna F — o mesmo que o adapter usa há tempos para separar dado de rodapé, e que
     * é o que impede uma linha de totais de virar dívida. A âncora posicional é o cabeçalho já
     * validado.
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
                $this->vazia($celulas) => BlocoRelatorio::Branca,
                $indice === $indiceCabecalho => BlocoRelatorio::Cabecalho,
                $indice < $indiceCabecalho => BlocoRelatorio::Cabecalho,
                $this->ehDado($celulas) => BlocoRelatorio::Dados,
                $this->ehCabecalhoBloco2($celulas) => BlocoRelatorio::CabecalhoBloco2,
                $this->formaDoTotalizador($celulas) !== null => BlocoRelatorio::Totalizador,
                default => BlocoRelatorio::Rodape,
            };

            if ($bloco === BlocoRelatorio::Dados) {
                $viuDado = true;
            }

            // Linha de texto solto ANTES do primeiro dado é cabeçalho; depois, é rodapé.
            if ($bloco === BlocoRelatorio::Rodape && !$viuDado) {
                $bloco = BlocoRelatorio::Cabecalho;
            }

            $linhas[] = new LinhaEspelhada(
                numeroLinha: $numeroLinha,
                bloco: $bloco,
                unidade: $this->texto($celulas[self::COL_UNIDADE] ?? null),
                sacado: $this->texto($celulas[self::COL_SACADO] ?? null),
                nn: $this->texto($celulas[self::COL_NN] ?? null),
                classe: $this->texto($celulas[self::COL_CLASSE] ?? null),
                competencia: $this->texto($celulas[self::COL_COMPETENCIA] ?? null),
                vencimento: $this->data($celulas[self::COL_VENCIMENTO] ?? null),
                atraso: $this->inteiro($celulas[self::COL_ATRASO] ?? null),
                valor: $this->centavos($celulas[self::COL_VALOR] ?? null),
                juros: $this->centavos($celulas[self::COL_JUROS] ?? null),
                multa: $this->centavos($celulas[self::COL_MULTA] ?? null),
                correcao: $this->centavos($celulas[self::COL_CORRECAO] ?? null),
                honorarios: $this->centavos($celulas[self::COL_HONORARIOS] ?? null),
                total: $this->centavos($celulas[self::COL_TOTAL] ?? null),
                acordoTexto: $this->texto($celulas[self::COL_ACORDO] ?? null),
                recebimento: $this->texto($celulas[self::COL_RECEBIMENTO] ?? null),
                bruto: array_map(fn (mixed $c): string => $this->texto($c) ?? '', $celulas),
            );
        }

        return $linhas;
    }

    /**
     * Normaliza as duas formas do bloco totalizador para os mesmos campos (SPEC §4.1, INV-T2).
     *
     * @param list<LinhaEspelhada> $linhas
     * @param list<list<mixed>>    $grade
     *
     * @return list<TotalizadorEspelhado>
     */
    private function extrairTotalizadores(array $linhas, array $grade): array
    {
        $totalizadores = [];

        foreach ($linhas as $linha) {
            if ($linha->bloco !== BlocoRelatorio::Totalizador) {
                continue;
            }

            $celulas = $grade[$linha->numeroLinha - 1];
            $forma = $this->formaDoTotalizador($celulas);

            if ($forma === null) {
                continue;
            }

            // Larga: rótulo em A, valores em H..M. Estreita: rótulo em A, valores em B..G.
            $base = $forma === FormaTotalizador::Larga ? self::COL_VALOR : 1;

            $totalizadores[] = new TotalizadorEspelhado(
                numeroLinha: $linha->numeroLinha,
                forma: $forma,
                rotulo: $this->texto($celulas[self::COL_UNIDADE] ?? null) ?? '',
                valor: $this->centavos($celulas[$base] ?? null),
                juros: $this->centavos($celulas[$base + 1] ?? null),
                multa: $this->centavos($celulas[$base + 2] ?? null),
                correcao: $this->centavos($celulas[$base + 3] ?? null),
                honorarios: $this->centavos($celulas[$base + 4] ?? null),
                total: $this->centavos($celulas[$base + 5] ?? null),
            );
        }

        return $totalizadores;
    }

    /**
     * Qual layout esta linha de totalizador usa, ou `null` se não for uma.
     *
     * @param list<mixed> $celulas
     */
    private function formaDoTotalizador(array $celulas): ?FormaTotalizador
    {
        if ($this->texto($celulas[self::COL_UNIDADE] ?? null) === null) {
            return null;
        }

        if ($this->numerica($celulas[self::COL_VALOR] ?? null)) {
            return FormaTotalizador::Larga;
        }

        if ($this->numerica($celulas[1] ?? null)) {
            return FormaTotalizador::Estreita;
        }

        return null;
    }

    /** @param list<mixed> $celulas */
    private function ehDado(array $celulas): bool
    {
        return $this->data($celulas[self::COL_VENCIMENTO] ?? null) !== null;
    }

    /** @param list<mixed> $celulas */
    private function ehCabecalhoBloco2(array $celulas): bool
    {
        $textos = array_map(fn (mixed $c): string => $this->texto($c) ?? '', array_slice($celulas, 0, 7));

        return $textos === self::CABECALHO_BLOCO2;
    }

    /** @param list<mixed> $celulas */
    private function vazia(array $celulas): bool
    {
        foreach ($celulas as $celula) {
            if ($this->texto($celula) !== null) {
                return false;
            }
        }

        return true;
    }

    /** @param list<list<mixed>> $grade */
    private function lerEmissao(array $grade): ?\DateTimeImmutable
    {
        $texto = $this->procurarDeBaixo($grade, '/^Emiss[ãa]o:\s*(\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2})/u');

        if ($texto === null) {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!d/m/Y H:i', $texto);

        return $data === false ? null : $data;
    }

    /**
     * A data até a qual a CONTABILIDADE calculou os encargos — a âncora da calibração.
     *
     * Vem de dentro da linha `Filtros:`, escrita sem espaço após os dois-pontos:
     * `Filtros:  Inadimplência até:12/08/2026; Competência: Todas; ...`
     *
     * @param list<list<mixed>> $grade
     */
    private function lerDadosAte(array $grade): ?\DateTimeImmutable
    {
        $texto = $this->procurarDeBaixo($grade, '/Inadimpl[êe]ncia at[ée]:\s*(\d{2}\/\d{2}\/\d{4})/u');

        if ($texto === null) {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!d/m/Y', $texto);

        return $data === false ? null : $data;
    }

    /**
     * As taxas que a contabilidade declarou NESTA emissão, em basis points, da linha 4:
     * `Juros: 1,00% ao mês; Multa: 2,00%; Honorários: 20,00%.`
     *
     * Guardar isto é o que permite perceber que ela mudou uma regra. Comparar com a config da
     * carteira é trabalho da calibração, não daqui.
     *
     * @param list<list<mixed>> $grade
     *
     * @return array<string, int>|null
     */
    private function lerConfigDeclarada(array $grade): ?array
    {
        foreach (array_slice($grade, 0, 6) as $celulas) {
            $texto = $this->texto($celulas[self::COL_UNIDADE] ?? null);

            if ($texto === null || !str_contains($texto, '%')) {
                continue;
            }

            $config = [];

            foreach (['juros' => 'Juros', 'multa' => 'Multa', 'honorarios' => 'Honor[áa]rios'] as $chave => $rotulo) {
                if (preg_match('/' . $rotulo . ':\s*([\d.,]+)\s*%/u', $texto, $m) === 1) {
                    $config[$chave] = (int) round(((float) str_replace(',', '.', $m[1])) * 100);
                }
            }

            return $config === [] ? null : $config;
        }

        return null;
    }

    /**
     * ⚠️ É a contagem de unidades INADIMPLENTES da emissão, não o tamanho da carteira — e diverge da
     * contagem real de unidades distintas do arquivo. Guardar sim; nunca asserir igualdade.
     *
     * @param list<list<mixed>> $grade
     */
    private function lerUnidadesDeclaradas(array $grade): ?int
    {
        foreach (array_slice($grade, 0, 6) as $celulas) {
            $texto = $this->texto($celulas[self::COL_UNIDADE] ?? null);

            if ($texto !== null && preg_match('/N[úu]mero de unidades:\s*(\d+)/u', $texto, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * @param list<list<mixed>> $grade
     */
    private function procurarDeBaixo(array $grade, string $padrao): ?string
    {
        foreach (array_reverse($grade) as $celulas) {
            $texto = $this->texto($celulas[self::COL_UNIDADE] ?? null);

            if ($texto !== null && preg_match($padrao, $texto, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (is_float($valor) || is_int($valor)) {
            return (string) $valor;
        }

        if (!is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Para centavos. Célula vazia vira `null`, NÃO zero — vazio e zero são coisas diferentes na
     * planilha, e achatar os dois já seria interpretar (INV-L3). Texto não numérico também vira
     * `null`, mas continua íntegro em `$bruto`, então nada se perde.
     */
    private function centavos(mixed $valor): ?int
    {
        if (is_int($valor) || is_float($valor)) {
            return (int) round($valor * 100);
        }

        if (!is_string($valor)) {
            return null;
        }

        $normalizado = str_replace(',', '.', trim($valor));

        return is_numeric($normalizado) ? (int) round(((float) $normalizado) * 100) : null;
    }

    private function inteiro(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        if (is_float($valor)) {
            return (int) $valor;
        }

        if (is_string($valor) && is_numeric(trim($valor))) {
            return (int) trim($valor);
        }

        return null;
    }

    private function numerica(mixed $valor): bool
    {
        if (is_int($valor) || is_float($valor)) {
            return true;
        }

        return is_string($valor) && is_numeric(str_replace(',', '.', trim($valor)));
    }

    private function data(mixed $valor): ?\DateTimeImmutable
    {
        if (!is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $texto) !== 1) {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!d/m/Y', $texto);

        return ($data === false || $data->format('d/m/Y') !== $texto) ? null : $data;
    }
}
