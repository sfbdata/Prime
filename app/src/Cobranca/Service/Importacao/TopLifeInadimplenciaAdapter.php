<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Adapter ESPECÍFICO da fonte "Inadimplência detalhada" da contábil L.G (relatórios TOPLIFE) — §21.
 * NÃO é um importador universal (§24): conhece o layout desta fonte e o traduz para os conceitos
 * gerais do domínio (`BoletoImportavel`). Mapeamento e decisões: `docs/gestao-cobrancas/MAPEAMENTO_FONTE_TOPLIFE.md`.
 *
 * Layout: 1 aba; L1–L4 institucional; L6 cabeçalho; L7+ dados; rodapé de totais/filtros/emissão.
 * Colunas: A Unidade · B Sacado · C NN · D Classe de conta · E Competência · F Vencimento · G Atraso ·
 * H Valor · I Juros · J Multa · K Correção · L Honorários · M Total · N Acordo · O Recebimento.
 *
 * Regras: agrega por NN em UMA Obrigação (decisão C); principal = H de 1.1/1.14 + H de 1.6 (desconto);
 * encargos SEPARADOS (spec §9): juros = Σ I + H das linhas 1.4; multa = Σ J + H das linhas 1.5;
 * correção = Σ K; honorários informados = (classe 1.15 → H, senão L). Linha sem Vencimento válido →
 * IGNORADA; boleto sem Sacado / competência inválida / valor não numérico / sem principal (>0) →
 * REJEITADO com motivo.
 *
 * Linha SEM Nosso Número não é mais descartada: ela é dívida antiga que nunca teve boleto emitido
 * (73 linhas, R$ 10.694,66 medidos em 04/08/2026). Entra por `ReferenciaSubstituta`, agrupada por
 * (unidade, competência, vencimento) — spec `docs/specs/cobranca-divida-sem-numero-de-boleto.md`.
 * O Vencimento continua sendo o discriminador entre dado e rodapé; sem ele a linha segue IGNORADA,
 * e é isso que impede uma linha de totais de virar dívida.
 *
 * INV-E1: `juros + multa + correcao` é EXATAMENTE o antigo agregado `encargos` (Σ(I+J) + H de 1.4/1.5)
 * mais a correção (coluna K, antes descartada e igual a 0 em 100% do dado real). A separação redistribui
 * as mesmas parcelas em três acumuladores — nenhuma parcela entra duas vezes nem some. Se essa soma
 * mudar, saldos já existentes mudam.
 */
final class TopLifeInadimplenciaAdapter
{
    private const COL_UNIDADE = 0;
    private const COL_SACADO = 1;
    private const COL_NN = 2;
    private const COL_CLASSE = 3;
    private const COL_COMPETENCIA = 4;
    private const COL_VENCIMENTO = 5;
    private const COL_VALOR = 7;
    private const COL_JUROS = 8;
    private const COL_MULTA = 9;
    private const COL_CORRECAO = 10;
    private const COL_HONORARIOS = 11;
    private const COL_ACORDO = 13;

    private const CODIGOS_PRINCIPAL = ['1.1', '1.14'];
    private const CODIGO_DESCONTO = '1.6';
    /** Lançamento fechado de JUROS na coluna Valor (H) — vai para o acumulador de juros. */
    private const CODIGO_JUROS_LINHA = '1.4';
    /** Lançamento fechado de MULTA na coluna Valor (H) — vai para o acumulador de multa. */
    private const CODIGO_MULTA_LINHA = '1.5';
    private const CODIGO_HONORARIO_LINHA = '1.15';

    /** Formato da coluna "Informações do acordo" (spec §2): "Acordo 28 - Parc. 1/1". */
    private const REGEX_ACORDO = '/^Acordo\s+(\d+)\s*-\s*Parc\.\s*(\d+)\/(\d+)$/i';

    public function ler(string $caminhoArquivo): ResultadoLeitura
    {
        $reader = IOFactory::createReaderForFile($caminhoArquivo);
        $reader->setReadDataOnly(true);
        $linhas = $reader->load($caminhoArquivo)->getActiveSheet()->toArray(null, true, false, false);

        $inicio = $this->localizarInicioDados($linhas);
        if ($inicio === null) {
            return new ResultadoLeitura([], [], 0);
        }

        $ignoradas = 0;
        /** @var array<string, array{ordem:int, referencia:string, linhas: list<array<int, mixed>>}> $grupos */
        $grupos = [];
        $ordem = 0;

        for ($i = $inicio; $i < count($linhas); $i++) {
            $linha = $linhas[$i];
            $vencimento = $this->parseVencimento((string) ($linha[self::COL_VENCIMENTO] ?? ''));
            if ($vencimento === null) {
                ++$ignoradas; // rodapé/total/metadado: não é dado

                continue;
            }

            $nn = trim((string) ($linha[self::COL_NN] ?? ''));
            // Chave = Objeto (unidade principal) + NN (decisão C). Não assume NN globalmente único:
            // o mesmo NN em duas unidades vira boletos separados, sem fundir dinheiro de unidades.
            [$identificacao] = $this->separarUnidade(trim((string) ($linha[self::COL_UNIDADE] ?? '')));
            $competencia = trim((string) ($linha[self::COL_COMPETENCIA] ?? ''));
            // Sem NN, o que resta do boleto é o mês + o vencimento: linhas do mesmo
            // (unidade, competência, vencimento) formam UMA obrigação, como o NN faria — a taxa e a
            // energia do mesmo mês vinham no mesmo boleto. A chave do agrupamento É a referência,
            // então ela é injetiva por construção: dois grupos distintos nunca colidem.
            $referencia = $nn !== '' ? $nn : ReferenciaSubstituta::para($vencimento);
            $chave = $nn !== ''
                ? $identificacao . "\x1f" . $nn
                : $identificacao . "\x1f" . $referencia . "\x1f" . $competencia;
            if (!isset($grupos[$chave])) {
                $grupos[$chave] = ['ordem' => $ordem++, 'referencia' => $referencia, 'linhas' => []];
            }
            $grupos[$chave]['linhas'][] = $linha;
        }

        uasort($grupos, static fn ($a, $b) => $a['ordem'] <=> $b['ordem']);

        $importaveis = [];
        $rejeitadas = [];
        foreach ($grupos as $chave => $grupo) {
            $resultado = $this->montarBoleto((string) $chave, $grupo['referencia'], $grupo['linhas']);
            if ($resultado instanceof BoletoImportavel) {
                $importaveis[] = $resultado;

                continue;
            }
            $rejeitadas[] = $resultado;
        }

        return new ResultadoLeitura($importaveis, $rejeitadas, $ignoradas);
    }

    /**
     * @param list<array<int, mixed>> $linhas
     */
    private function montarBoleto(string $chave, string $referencia, array $linhas): BoletoImportavel|LinhaRejeitada
    {
        $primeira = $linhas[0];
        $unidadeRaw = trim((string) ($primeira[self::COL_UNIDADE] ?? ''));
        $sacado = trim((string) ($primeira[self::COL_SACADO] ?? ''));
        // Dívida antiga que nunca teve boleto entra pela referência substituta, não pelo NN.
        $nn = $referencia;
        $competencia = $this->competenciaDoBoleto($linhas);
        $dados = ['nn' => $nn, 'unidade' => $unidadeRaw, 'sacado' => $sacado, 'competencia' => $competencia];

        if ($sacado === '') {
            return new LinhaRejeitada($nn, 'Boleto sem Sacado (devedor).', $dados);
        }
        if (!preg_match('#^\d{2}/\d{4}$#', $competencia)) {
            return new LinhaRejeitada($nn, 'Competência ausente ou inválida (esperado MM/AAAA).', $dados);
        }

        $principal = 0;
        // Encargos separados (spec §9). A soma dos três acumuladores reproduz, ao centavo, o antigo
        // acumulador único `$encargos` — ver INV-E1 no docblock da classe.
        $jurosTotal = 0;
        $multaTotal = 0;
        $correcaoTotal = 0;
        $honorarios = 0;
        $detalhe = [];
        $vencimento = null;
        $acordo = null;
        $acordoReconhecido = null;
        // Soma da coluna Valor de TODAS as linhas do NN, independente de classe (spec §3.2) — o
        // "principal negociado" quando o NN é parcela de acordo. Diferente de $principal, que só
        // soma classes específicas (1.1/1.14/1.6).
        $somaColunaValor = 0;

        foreach ($linhas as $linha) {
            $codigo = $this->codigoClasse((string) ($linha[self::COL_CLASSE] ?? ''));
            $valor = $this->parseCentavos($linha[self::COL_VALOR] ?? null);
            $juros = $this->parseCentavos($linha[self::COL_JUROS] ?? null);
            $multa = $this->parseCentavos($linha[self::COL_MULTA] ?? null);
            $correcao = $this->parseCentavos($linha[self::COL_CORRECAO] ?? null);
            $hono = $this->parseCentavos($linha[self::COL_HONORARIOS] ?? null);
            if ($valor === false || $juros === false || $multa === false || $correcao === false || $hono === false) {
                return new LinhaRejeitada($nn, 'Valor não numérico em uma das linhas do boleto.', $dados);
            }

            if (in_array($codigo, self::CODIGOS_PRINCIPAL, true) || $codigo === self::CODIGO_DESCONTO) {
                $principal += $valor;
            }
            $somaColunaValor += $valor;
            // Colunas I/J/K de TODA linha entram nos acumuladores correspondentes (antes I e J caíam
            // juntas em `$encargos` e K era descartada).
            $jurosTotal += $juros;
            $multaTotal += $multa;
            $correcaoTotal += $correcao;
            // Lançamentos fechados na coluna Valor: 1.4 é juros, 1.5 é multa. Antes ambos somavam no
            // mesmo balde — era exatamente esta informação que se perdia.
            if ($codigo === self::CODIGO_JUROS_LINHA) {
                $jurosTotal += $valor;
            }
            if ($codigo === self::CODIGO_MULTA_LINHA) {
                $multaTotal += $valor;
            }
            $honorarios += $codigo === self::CODIGO_HONORARIO_LINHA ? $valor : $hono;

            $vencimento ??= $this->parseVencimento((string) ($linha[self::COL_VENCIMENTO] ?? ''));
            $acordo ??= $this->textoAcordo((string) ($linha[self::COL_ACORDO] ?? ''));
            $acordoReconhecido ??= $this->acordoDoRelatorio((string) ($linha[self::COL_ACORDO] ?? ''));
            $detalhe[] = [
                'classe' => trim((string) ($linha[self::COL_CLASSE] ?? '')),
                'valor' => $valor,
                'juros' => $juros,
                'multa' => $multa,
                'correcao' => $correcao,
                'honorarios' => $hono,
            ];
        }

        if ($principal <= 0) {
            return new LinhaRejeitada($nn, 'Boleto sem principal de dívida (apenas encargos/honorários).', $dados);
        }
        if ($vencimento === null) {
            return new LinhaRejeitada($nn, 'Boleto sem vencimento válido.', $dados);
        }

        [$identificacao, $metadata] = $this->separarUnidade($unidadeRaw);

        return new BoletoImportavel(
            nn: $nn,
            objetoIdentificacao: $identificacao,
            unidadeMetadata: $metadata,
            sacadoNome: $sacado,
            principalCentavos: $principal,
            jurosCentavos: $jurosTotal,
            multaCentavos: $multaTotal,
            correcaoCentavos: $correcaoTotal,
            honorariosInformadosCentavos: $honorarios,
            vencimento: $vencimento,
            competencia: $competencia,
            acordoTexto: $acordo,
            acordo: $acordoReconhecido,
            somaColunaValorCentavos: $somaColunaValor,
            linhas: $detalhe,
        );
    }

    /**
     * @param list<array<int, mixed>> $linhas
     */
    private function competenciaDoBoleto(array $linhas): string
    {
        foreach ($linhas as $linha) {
            $codigo = $this->codigoClasse((string) ($linha[self::COL_CLASSE] ?? ''));
            if (in_array($codigo, self::CODIGOS_PRINCIPAL, true)) {
                return trim((string) ($linha[self::COL_COMPETENCIA] ?? ''));
            }
        }

        return trim((string) ($linhas[0][self::COL_COMPETENCIA] ?? ''));
    }

    /**
     * Localiza a primeira linha de DADOS (após o cabeçalho "Unidade" na col A). Null se não achar.
     *
     * @param list<array<int, mixed>> $linhas
     */
    private function localizarInicioDados(array $linhas): ?int
    {
        foreach ($linhas as $i => $linha) {
            if (strcasecmp(trim((string) ($linha[self::COL_UNIDADE] ?? '')), 'Unidade') === 0) {
                return $i + 1;
            }
        }

        return null;
    }

    /** Código da classe de conta antes do " - " (ex.: "1.14 - Energia" → "1.14"). */
    private function codigoClasse(string $classe): string
    {
        $partes = explode(' - ', trim($classe), 2);

        return trim($partes[0]);
    }

    /** "01-03A (05-03,06-01)" → ["01-03A", "05-03,06-01"]; sem parênteses → [unidade, null]. */
    private function separarUnidade(string $unidade): array
    {
        if (preg_match('/^(.*?)\s*\((.*)\)\s*$/', $unidade, $m) === 1) {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($unidade), null];
    }

    private function textoAcordo(string $valor): ?string
    {
        $valor = trim($valor);

        return ($valor === '' || $valor === '-') ? null : $valor;
    }

    /**
     * Reconhece "Acordo <N> - Parc. <p>/<t>" (spec §3.1); qualquer outra coisa (vazio, "-", texto
     * livre que não casa) volta `null` — obrigação comum, comportamento atual preservado. O texto
     * cru continua indo para `observacao()` via `textoAcordo()`, casando ou não a regex.
     */
    private function acordoDoRelatorio(string $valor): ?AcordoDoRelatorio
    {
        $valor = trim($valor);
        if (preg_match(self::REGEX_ACORDO, $valor, $m) !== 1) {
            return null;
        }

        return new AcordoDoRelatorio((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    private function parseVencimento(string $valor): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $valor) !== 1) {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!d/m/Y', $valor);
        if ($data === false || $data->format('d/m/Y') !== $valor) {
            return null;
        }

        return $data;
    }

    /**
     * Converte para centavos inteiros. Vazio/null → 0; numérico → arredondado ×100; texto não
     * numérico → false (sinaliza rejeição do boleto).
     */
    private function parseCentavos(mixed $valor): int|false
    {
        if ($valor === null || $valor === '') {
            return 0;
        }
        if (is_int($valor) || is_float($valor)) {
            return (int) round($valor * 100);
        }
        if (is_string($valor) && is_numeric(str_replace(',', '.', $valor))) {
            return (int) round(((float) str_replace(',', '.', $valor)) * 100);
        }

        return false;
    }
}
