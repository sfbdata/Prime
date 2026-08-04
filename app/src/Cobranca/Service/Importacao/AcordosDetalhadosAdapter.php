<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Adapter ESPECÍFICO da fonte "Acordos detalhados" da contábil L.G — irmão do
 * `TopLifeInadimplenciaAdapter` e do `CadastroCondominosAdapter`, e como eles NÃO é um importador
 * universal: conhece este layout e o traduz para os conceitos do domínio.
 * Spec: `docs/specs/cobranca-importar-acordos-detalhados.md` §2.
 *
 * Layout medido em 2026-07-29 (7 abas, 25 contas originais, 12 parcelas): UMA ABA POR ACORDO, em
 * formato de ficha, não de tabela.
 *
 * - L1–L5 institucional/título;
 * - cabeçalho L6–L10: `Acordo de número N` · `Unidade:` + `Data base:` · `Sacado:` +
 *   `Valor total das contas originais:` · `Criado em:` + `Valor final acordado:` · `Situação:`;
 * - seção `Relação das contas originais` — A NN · B Classe · C Competência · D Vencimento ·
 *   E Valor original · F Detalhamento (com uma linha VAZIA entre cada conta);
 * - seção `Parcelas das contas geradas pelo acordo` — A NN · B Classe · C Parcela (`p/t`) ·
 *   D Competência · E Vencimento · F Liquidação · G Valor acordado · H Valor liquidado;
 * - rodapé `Filtros:` · endereço · `Emissão:`.
 *
 * Três fatos do dado real que a implementação tem de respeitar (e que uma fixture inventada não teria):
 *
 * 1. **Toda célula é STRING**, inclusive dinheiro e data. "170,00" não chega como float.
 * 2. **O desconto vem `-\u{00A0}3,04`**: o sinal é separado do número por um espaço NÃO-QUEBRÁVEL.
 *    Sem limpar isso, a linha é recusada e a parcela inteira (R$ 400,68) se perde.
 * 3. **O cabeçalho usa separador de milhar** ("1.020,00").
 *
 * E a decisão que mais importa: a **competência da parcela é a da COLUNA Competência**, não a que
 * aparece no fim da classe de conta. A coluna é o mês do boleto da parcela e casa com o que a
 * inadimplência traz para o mesmo NN (medido: NN 61600 → 07/2026 nas duas fontes); o sufixo da classe é
 * o mês da taxa RENEGOCIADA, outra coisa. Usar o sufixo faria a próxima importação de inadimplência
 * criar uma SEGUNDA obrigação para o mesmo boleto — dinheiro cobrado duas vezes.
 */
final class AcordosDetalhadosAdapter
{
    private const COL_NN = 0;
    private const COL_CLASSE = 1;

    /** Seção "contas originais": C Competência · D Vencimento · E Valor. */
    private const ORIG_COMPETENCIA = 2;
    private const ORIG_VENCIMENTO = 3;
    private const ORIG_VALOR = 4;

    /** Seção "parcelas": C Parcela · D Competência · E Vencimento · F Liquidação · G Valor. */
    private const PARC_PARCELA = 2;
    private const PARC_COMPETENCIA = 3;
    private const PARC_VENCIMENTO = 4;
    private const PARC_LIQUIDACAO = 5;
    private const PARC_VALOR = 6;

    private const MARCADOR_ORIGINAIS = 'relacao das contas originais';
    private const MARCADOR_PARCELAS = 'parcelas das contas geradas pelo acordo';

    public function ler(string $caminhoArquivo): ResultadoLeituraAcordos
    {
        $reader = IOFactory::createReaderForFile($caminhoArquivo);
        $reader->setReadDataOnly(true);
        $planilha = $reader->load($caminhoArquivo);

        $acordos = [];
        $rejeitadas = [];
        $ignoradas = 0;

        foreach ($planilha->getAllSheets() as $aba) {
            $resultado = $this->lerAba($aba->getTitle(), $aba->toArray(null, true, false, false), $ignoradas, $rejeitadas);
            if ($resultado instanceof AcordoDetalhadoImportavel) {
                $acordos[] = $resultado;

                continue;
            }
            $rejeitadas[] = $resultado;
        }

        return new ResultadoLeituraAcordos($acordos, $rejeitadas, $ignoradas);
    }

    /**
     * @param list<array<int, mixed>> $linhas
     * @param list<LinhaRejeitada>    $rejeitadas acumulador das rejeições de LINHA (a aba segue válida)
     */
    private function lerAba(string $titulo, array $linhas, int &$ignoradas, array &$rejeitadas): AcordoDetalhadoImportavel|LinhaRejeitada
    {
        $numero = null;
        $unidade = '';
        $sacado = '';
        $situacao = '';
        $dataBase = null;
        $criadoEm = null;
        $totalOriginais = null;
        $valorFinal = null;
        $emissao = null;

        $secao = null;
        /** @var array<string, list<array<int, mixed>>> $linhasPorConta chave "NN|competência" → linhas */
        $linhasPorConta = [];
        /** @var array<string, string> $ordemContas chave → NN, na ordem de aparição */
        $ordemContas = [];
        /** @var array<string, list<array<int, mixed>>> $linhasPorParcela */
        $linhasPorParcela = [];
        /** @var list<string> $ordemParcelas */
        $ordemParcelas = [];

        foreach ($linhas as $linha) {
            $a = trim((string) ($linha[self::COL_NN] ?? ''));
            $b = trim((string) ($linha[self::COL_CLASSE] ?? ''));
            $chave = $this->semAcento($a);

            // --- cabeçalho e rodapé ------------------------------------------------------------
            if (preg_match('/^acordo de numero\s+(\d+)/', $chave, $m) === 1) {
                $numero = (int) $m[1];
                ++$ignoradas;

                continue;
            }
            if ($numero === null) {
                ++$ignoradas; // institucional acima do cabeçalho

                continue;
            }
            if (str_starts_with($chave, 'unidade:')) {
                $unidade = $this->depoisDoRotulo($a);
                $dataBase = $this->parseData($this->depoisDoRotulo($b));
                ++$ignoradas;

                continue;
            }
            if (str_starts_with($chave, 'sacado:')) {
                $sacado = $this->depoisDoRotulo($a);
                $totalOriginais = $this->valorDoRotulo($b);
                ++$ignoradas;

                continue;
            }
            if (str_starts_with($chave, 'criado em:')) {
                $criadoEm = $this->parseData($this->depoisDoRotulo($a));
                $valorFinal = $this->valorDoRotulo($b);
                ++$ignoradas;

                continue;
            }
            if (str_starts_with($chave, 'situacao:')) {
                $situacao = $this->depoisDoRotulo($a);
                ++$ignoradas;

                continue;
            }
            if (str_starts_with($chave, 'emissao:')) {
                $emissao = $this->parseData(substr($this->depoisDoRotulo($a), 0, 10));
                $secao = null;
                ++$ignoradas;

                continue;
            }
            if (str_starts_with($chave, 'filtros:')) {
                $secao = null; // o rodapé fecha a seção corrente
                ++$ignoradas;

                continue;
            }

            // --- marcadores de seção -----------------------------------------------------------
            if ($chave === self::MARCADOR_ORIGINAIS) {
                $secao = 'originais';
                ++$ignoradas;

                continue;
            }
            if ($chave === self::MARCADOR_PARCELAS) {
                $secao = 'parcelas';
                ++$ignoradas;

                continue;
            }

            // Linha de DADOS é a que tem NN (só dígitos) na coluna A. Cabeçalho de coluna
            // ("Nosso Número"), linhas vazias e o endereço da contábil caem fora por construção.
            if ($secao === null || preg_match('/^\d+$/', $a) !== 1) {
                ++$ignoradas;

                continue;
            }

            if ($secao === 'originais') {
                // Agrupa como a seção de parcelas já faz: a fonte pode quebrar UMA conta em várias
                // linhas (principal, juros, multa, desconto). Uma linha por objeto faria o UseCase
                // processar a mesma dívida duas vezes — na reconstrução (§3.2.1) seriam duas obrigações
                // com o mesmo NN+competência, e o índice único derrubaria o lote inteiro.
                // A chave é NN + COMPETÊNCIA, nunca o NN sozinho: é a mesma regra do casamento.
                $chaveConta = $a . '|' . trim((string) ($linha[self::ORIG_COMPETENCIA] ?? ''));
                if (!isset($linhasPorConta[$chaveConta])) {
                    $linhasPorConta[$chaveConta] = [];
                    $ordemContas[$chaveConta] = $a;
                }
                $linhasPorConta[$chaveConta][] = $linha;

                continue;
            }

            if (!isset($linhasPorParcela[$a])) {
                $linhasPorParcela[$a] = [];
                $ordemParcelas[] = $a;
            }
            $linhasPorParcela[$a][] = $linha;
        }

        if ($numero === null) {
            return new LinhaRejeitada($titulo, 'Aba sem "Acordo de número N" — não é uma ficha de acordo.', ['aba' => $titulo]);
        }

        $contas = [];
        foreach ($ordemContas as $chaveConta => $nn) {
            $conta = $this->montarContaOriginal($nn, $linhasPorConta[$chaveConta]);
            if ($conta instanceof LinhaRejeitada) {
                $rejeitadas[] = $conta;

                continue;
            }
            $contas[] = $conta;
        }

        $parcelas = [];
        foreach ($ordemParcelas as $nn) {
            $parcela = $this->montarParcela($numero, $nn, $linhasPorParcela[$nn]);
            if ($parcela instanceof LinhaRejeitada) {
                $rejeitadas[] = $parcela;

                continue;
            }
            $parcelas[] = $parcela;
        }

        return new AcordoDetalhadoImportavel(
            numero: $numero,
            unidade: $unidade,
            sacado: $sacado,
            situacao: $situacao,
            dataBase: $dataBase,
            criadoEm: $criadoEm,
            valorTotalContasOriginaisCentavos: $totalOriginais,
            valorFinalAcordadoCentavos: $valorFinal,
            emissao: $emissao,
            contasOriginais: $contas,
            parcelas: $parcelas,
        );
    }

    /**
     * Uma conta original é o GRUPO de linhas do mesmo NN+competência, somado — pelo mesmo motivo que a
     * parcela é (`montarParcela`): a fonte quebra um boleto em principal, juros, multa e desconto, e o
     * que casa com a `Obrigacao` do sistema é o boleto inteiro. Competência e vencimento vêm da primeira
     * linha; o valor é a soma de todas.
     *
     * @param list<array<int, mixed>> $linhas
     */
    private function montarContaOriginal(string $nn, array $linhas): ContaOriginalImportavel|LinhaRejeitada
    {
        $primeira = $linhas[0];
        $competencia = trim((string) ($primeira[self::ORIG_COMPETENCIA] ?? ''));
        $dados = ['nn' => $nn, 'competencia' => $competencia];

        // Sem competência não há casamento seguro: casar só pelo NN marcaria dívida de terceiro como
        // substituída (§1 da spec — R$ 435,00 de cobrança legítima em risco). Recusar é o correto.
        if (preg_match('#^\d{2}/\d{4}$#', $competencia) !== 1) {
            return new LinhaRejeitada($nn, 'Conta original sem competência MM/AAAA — sem ela o casamento com o boleto do sistema seria feito só pelo NN, o que é proibido.', $dados);
        }

        $vencimento = $this->parseData(trim((string) ($primeira[self::ORIG_VENCIMENTO] ?? '')));
        if ($vencimento === null) {
            return new LinhaRejeitada($nn, 'Conta original sem vencimento válido (dd/mm/aaaa).', $dados);
        }

        $valor = 0;
        foreach ($linhas as $l) {
            $parcial = $this->parseCentavos($l[self::ORIG_VALOR] ?? null);
            if ($parcial === false) {
                return new LinhaRejeitada($nn, 'Valor original não numérico em uma das linhas da conta.', $dados + ['valor' => (string) ($l[self::ORIG_VALOR] ?? '')]);
            }
            $valor += $parcial;
        }

        // Conta original pode virar Obrigação NOVA (§3.2.1). Reconstruir uma dívida de valor zero ou
        // negativo seria inventar passivo a partir de célula vazia ou de um lançamento que não é dívida;
        // e num rompimento essa linha voltaria para a cobrança. Recusar e reportar é o correto.
        if ($valor <= 0) {
            return new LinhaRejeitada($nn, sprintf('Conta original com valor não positivo (%s) — não é dívida reconstruível.', number_format($valor / 100, 2, ',', '.')), $dados + ['valor' => (string) ($primeira[self::ORIG_VALOR] ?? '')]);
        }

        return new ContaOriginalImportavel(
            nn: $nn,
            classe: $this->classeNormalizada((string) ($primeira[self::COL_CLASSE] ?? '')),
            competencia: $competencia,
            vencimento: $vencimento,
            valorCentavos: $valor,
        );
    }

    /**
     * O valor da parcela é a SOMA da coluna "Valor acordado" de TODAS as linhas do NN — inclusive as de
     * juros, multa, desconto (negativo) e honorário. É assim que a fonte compõe o boleto, e é o mesmo
     * número que o `TopLifeInadimplenciaAdapter` produz em `somaColunaValorCentavos` para o mesmo NN.
     *
     * @param list<array<int, mixed>> $linhas
     */
    private function montarParcela(int $acordoNumero, string $nn, array $linhas): ParcelaAcordoImportavel|LinhaRejeitada
    {
        $primeira = $linhas[0];
        $competencia = trim((string) ($primeira[self::PARC_COMPETENCIA] ?? ''));
        $dados = ['nn' => $nn, 'acordo' => $acordoNumero, 'competencia' => $competencia];

        if (preg_match('#^\d{2}/\d{4}$#', $competencia) !== 1) {
            return new LinhaRejeitada($nn, 'Parcela sem competência MM/AAAA.', $dados);
        }

        $vencimento = $this->parseData(trim((string) ($primeira[self::PARC_VENCIMENTO] ?? '')));
        if ($vencimento === null) {
            return new LinhaRejeitada($nn, 'Parcela sem vencimento válido (dd/mm/aaaa).', $dados);
        }

        if (preg_match('#^(\d+)\s*/\s*(\d+)$#', trim((string) ($primeira[self::PARC_PARCELA] ?? '')), $m) !== 1) {
            return new LinhaRejeitada($nn, 'Parcela sem o indicador "p/t" (ex.: 1/4).', $dados);
        }

        $total = 0;
        $classes = [];
        $liquidada = false;
        foreach ($linhas as $l) {
            $valor = $this->parseCentavos($l[self::PARC_VALOR] ?? null);
            if ($valor === false) {
                return new LinhaRejeitada($nn, 'Valor acordado não numérico em uma das linhas da parcela.', $dados + ['valor' => (string) ($l[self::PARC_VALOR] ?? '')]);
            }
            $total += $valor;
            $classes[] = $this->classeNormalizada((string) ($l[self::COL_CLASSE] ?? ''));
            $liquidada = $liquidada || $this->preenchido((string) ($l[self::PARC_LIQUIDACAO] ?? ''));
        }

        if ($total <= 0) {
            return new LinhaRejeitada($nn, sprintf('Parcela com valor total não positivo (%d centavos).', $total), $dados);
        }

        return new ParcelaAcordoImportavel(
            acordoNumero: $acordoNumero,
            nn: $nn,
            numero: (int) $m[1],
            total: (int) $m[2],
            competencia: $competencia,
            vencimento: $vencimento,
            valorCentavos: $total,
            classes: $classes,
            constaLiquidada: $liquidada,
        );
    }

    /**
     * `1.15 - Honorário advocatício - 15,00% com reajuste - 07/2026` → `1.15 - Honorário advocatício`.
     * Só os dois primeiros segmentos: o resto é o parâmetro da taxa e a competência da taxa renegociada.
     * Resultado idêntico ao rótulo que a inadimplência grava, de modo que uma obrigação criada por este
     * importador não se distinga de uma criada por aquele.
     */
    private function classeNormalizada(string $classe): string
    {
        $partes = array_map('trim', explode(' - ', trim($classe)));
        if (count($partes) <= 2) {
            return trim($classe);
        }

        return $partes[0] . ' - ' . $partes[1];
    }

    /** "Unidade: QUADRA 01" → "QUADRA 01"; sem `:` devolve a string aparada. */
    private function depoisDoRotulo(string $valor): string
    {
        $partes = explode(':', trim($valor), 2);

        return trim($partes[1] ?? $partes[0]);
    }

    private function valorDoRotulo(string $valor): ?int
    {
        $centavos = $this->parseCentavos($this->depoisDoRotulo($valor));

        return $centavos === false ? null : $centavos;
    }

    /** Célula preenchida? Nesta fonte, "-" significa VAZIO (liquidação não ocorrida). */
    private function preenchido(string $valor): bool
    {
        $valor = trim($valor);

        return $valor !== '' && $valor !== '-';
    }

    private function parseData(string $valor): ?\DateTimeImmutable
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
     * Converte o dinheiro pt-BR desta fonte para centavos inteiros. Vazio e hífen → 0; texto não numérico
     * → `false` (rejeita a linha em vez de virar zero silencioso).
     *
     * TRÊS particularidades MEDIDAS na fonte, todas capazes de derrubar uma parcela inteira se ignoradas:
     *
     * 1. o desconto chega como `-\u{00A0}3,04` (espaço não-quebrável entre o sinal e o número);
     * 2. o cabeçalho usa separador de milhar (`1.020,00`);
     * 3. **o zero é escrito `-`**, nas linhas de Juros e Multas de todo acordo recém-firmado.
     *
     * Por isso todo espaço — comum ou NBSP — é removido antes, e o ponto só é tratado como milhar quando
     * existe vírgula decimal na string.
     *
     * ⚠️ O hífen tem de casar o token INTEIRO. `-` sozinho é zero, mas `-\u{00A0}3,04` é DESCONTO: um
     * `str_replace('-', '')` aqui trocaria o defeito do item 3 pelo do item 1 — e o item 1 já custou uma
     * parcela de R$ 400,68 quando foi descoberto. A convenção "vazio ou hífen = ausente" é a mesma que
     * `preenchido()` aplica na coluna Liquidação; ela valia para as duas colunas, e faltava aqui.
     * Medido em 04/08/2026: o hífen não reconhecido descartava 172 parcelas, R$ 49.038,17 que nunca
     * entrariam no saldo. Spec: `docs/specs/cobranca-adapter-acordos-hifen-zero.md`.
     */
    private function parseCentavos(mixed $valor): int|false
    {
        if ($valor === null) {
            return 0;
        }
        if (is_int($valor) || is_float($valor)) {
            return (int) round($valor * 100);
        }

        $limpo = preg_replace('/[\s\x{00A0}]+/u', '', trim((string) $valor)) ?? '';
        if ($limpo === '' || $limpo === '-') {
            return 0;
        }
        if (str_contains($limpo, ',')) {
            $limpo = str_replace(',', '.', str_replace('.', '', $limpo));
        }

        return is_numeric($limpo) ? (int) round(((float) $limpo) * 100) : false;
    }

    /** Minúsculas sem acento, para reconhecer rótulo/marcador sem depender da acentuação da fonte. */
    private function semAcento(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));

        return strtr($valor, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ç' => 'c',
        ]);
    }
}
