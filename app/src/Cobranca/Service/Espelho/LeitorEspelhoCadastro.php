<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\BlocoRelatorio;
use App\Cobranca\Enum\TipoRelatorioContabil;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lê o relatório de DADOS CADASTRAIS DOS CONDÔMINOS
 * (SPEC docs/specs/cobranca-espelho-quatro-relatorios.md §3.4).
 *
 * 🔑 **INV-Q2 — este relatório não publica dinheiro.** Nenhuma coluna de valor, encargo ou pagamento.
 * O que ele responde é identidade: o sistema conhece as mesmas unidades e os mesmos sacados que ela?
 * Um número de dinheiro que "cobre o cadastro" não existe, e a declaração de cobertura dos comandos
 * diz isso em vez de exibir uma fração de 4 que sugeriria o contrário.
 *
 * ⚠️ **PII densa**: CPF/CNPJ, e-mail e telefone de condômino. O espelho guarda a linha bruta como
 * qualquer outra, sob o mesmo filtro de tenant — mas **nenhuma saída de comando imprime coluna deste
 * relatório**. Ele entra para ser conferido em contagem e identidade, não para ser listado.
 */
final class LeitorEspelhoCadastro implements LeitorDeEspelho
{
    /** Incrementar quando ESTA leitura mudar — não afeta os lotes dos outros três layouts. */
    public const VERSAO = 1;

    use LeCelulasDoEspelho;

    private const CABECALHO_ESPERADO = [
        'Unidade',
        'Nome/Nome fantasia',
        'Sacado',
        'CPF/CNPJ',
        'Fração ideal',
        'Endereço',
        'E-mail',
        'Telefone',
    ];

    private const COL_UNIDADE = 0;
    private const COL_NOME = 1;
    private const COL_SACADO = 2;

    public function tipo(): TipoRelatorioContabil
    {
        return TipoRelatorioContabil::Cadastro;
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

        $planilha->disconnectWorksheets();
        unset($planilha, $reader);

        $indiceCabecalho = $this->localizarCabecalho($grade);

        return new ArquivoEspelhado(
            arquivoNome: basename($caminhoArquivo),
            arquivoHash: $hash,
            emitidoEm: $this->lerEmissao($grade),
            dadosAte: null,
            configDeclarada: null,
            unidadesDeclaradas: $this->lerUnidadesDeclaradas($grade),
            linhasTotal: count($grade),
            linhas: $this->classificar($grade, $indiceCabecalho),
            totalizadores: [],
        );
    }

    /**
     * O portão do cadastro é ESTRUTURAL, não de contagem — e isso é decisão medida, não preguiça.
     *
     * 🔴 **`Total de unidades filtradas` NÃO é confiável como régua.** Medido nos 3 arquivos de
     * 10/08/2026:
     *
     * | carteira | o rótulo declara | linhas de dado |
     * |---|---:|---:|
     * | TOP LIFE I | 230 | 242 |
     * | TOP LIFE II | **230** | 243 |
     * | AMLI BR 060 | 51 | 52 |
     *
     * Duas carteiras diferentes declaram **o mesmo 230**, e nenhuma bate com as próprias linhas. Um
     * portão de contagem recusaria os três arquivos, todo mês, para sempre.
     *
     * O precedente já existe neste módulo: o `LeitorEspelhoRelatorio` guarda
     * `Número de unidades:` da inadimplência com a ressalva *"guardar sim; nunca asserir igualdade"*.
     * Aqui vale a mesma regra, pelo mesmo motivo — a contagem dela conta outra coisa (unidade × sacado,
     * inativas, pseudo-unidades como `RECEITAS`), e adivinhar o quê seria interpretar.
     *
     * O que este portão exige: cabeçalho de 8 colunas encontrado (já garantido por `ler()`) e pelo
     * menos uma linha de dado. Arquivo de cadastro sem nenhum condômino é erro de emissão, não
     * carteira vazia — a carteira existir implica unidade.
     */
    public function exigirReconciliacaoInterna(ArquivoEspelhado $espelhado): void
    {
        if ($espelhado->linhasDeDados() !== []) {
            return;
        }

        throw new ReconciliacaoInternaFalhouException(sprintf(
            'A planilha "%s" tem o cabeçalho de dados cadastrais mas nenhuma linha de condômino. '
            . 'Isso é emissão vazia, não carteira sem unidade. Nada foi gravado.',
            $espelhado->arquivoNome,
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
            'Cabeçalho de dados cadastrais não encontrado. Esperado, em uma única linha: %s.',
            implode(' · ', self::CABECALHO_ESPERADO),
        ));
    }

    /**
     * INV-Q5: toda linha em exatamente um balde.
     *
     * Sem coluna de vencimento, o discriminador de dado é outro: **estar depois do cabeçalho e ter
     * unidade preenchida**, descontadas as linhas de rodapé (`Filtros:`, `Emissão:`, a razão social).
     *
     * @param list<list<mixed>> $grade
     *
     * @return list<LinhaEspelhada>
     */
    private function classificar(array $grade, int $indiceCabecalho): array
    {
        $linhas = [];

        foreach ($grade as $indice => $celulas) {
            $numeroLinha = $indice + 1;

            $bloco = match (true) {
                $this->linhaVazia($celulas) => BlocoRelatorio::Branca,
                $indice <= $indiceCabecalho => BlocoRelatorio::Cabecalho,
                $this->ehRodape($celulas) => BlocoRelatorio::Rodape,
                $this->texto($celulas[self::COL_UNIDADE] ?? null) !== null => BlocoRelatorio::Dados,
                default => BlocoRelatorio::Rodape,
            };

            $ehDado = $bloco === BlocoRelatorio::Dados;

            $linhas[] = new LinhaEspelhada(
                numeroLinha: $numeroLinha,
                bloco: $bloco,
                unidade: $ehDado ? $this->texto($celulas[self::COL_UNIDADE] ?? null) : null,
                // A coluna 1 é `Nome/Nome fantasia` (quem é o condômino) e a 2 é `Sacado`, que aqui
                // guarda o PAPEL (`Proprietário`), não um nome. O sacado do espelho é o nome — trocar
                // as duas encheria a coluna de "Proprietário" repetido e quebraria o casamento por
                // nome com as outras fontes.
                sacado: $ehDado ? $this->texto($celulas[self::COL_NOME] ?? null) : null,
                nn: null,
                classe: $ehDado ? $this->texto($celulas[self::COL_SACADO] ?? null) : null,
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
            );
        }

        return $linhas;
    }

    /** @param list<mixed> $celulas */
    private function ehRodape(array $celulas): bool
    {
        $texto = $this->texto($celulas[0] ?? null);

        if ($texto === null) {
            return false;
        }

        return str_starts_with($texto, 'Filtros')
            || str_starts_with($texto, 'Emissão')
            || str_starts_with($texto, 'Emissao')
            || str_starts_with($texto, 'L. G');
    }

    /**
     * ⚠️ Guardado, NUNCA asserido — ver o docblock de `exigirReconciliacaoInterna()`.
     *
     * @param list<list<mixed>> $grade
     */
    private function lerUnidadesDeclaradas(array $grade): ?int
    {
        foreach (array_slice($grade, 0, 8) as $celulas) {
            $texto = $this->texto($celulas[0] ?? null);

            if ($texto !== null && preg_match('/Total de unidades[^:]*:\s*(\d+)/u', $texto, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
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
