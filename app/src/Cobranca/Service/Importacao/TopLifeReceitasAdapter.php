<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Adapter ESPECÍFICO da fonte "Receitas detalhadas por unidade/cliente" da contábil L.G — o 4º e último
 * relatório (spec `docs/specs/cobranca-importar-receitas.md`). Como os irmãos, NÃO é importador
 * universal: conhece este layout e o traduz para `ReceitaImportavel`.
 *
 * Layout (medido e reconferido em 03/08): cabeçalho na linha 7, dados a partir da 8.
 * A Unidade · B Sacado · C NN · D Classe de Conta · E Competência · F Vencimento · G Recebimento ·
 * H Valor · I Valor recebido · J Informações do acordo.
 *
 * Regras, todas medidas:
 *
 * - **O dinheiro é a coluna I** (`Valor recebido`), nunca a H (decisão R2). Elas divergem só nas classes
 *   `1.4`, `1.5` e `1.6` — nos juros/multa o recebido é MAIOR (acumulou até o pagamento) e no desconto é
 *   mais negativo (o abatimento efetivamente concedido). Na taxa e no honorário são idênticas.
 * - ⚠️ **`Recebimento = "-"` → linha EM ABERTO, descartada.** É o boleto que ainda não foi pago, que a
 *   contábil inclui quando o export pede a situação "Aberta" (a linha de filtros do próprio arquivo
 *   registra isso). Remedido em 03/08: **2.094 linhas somando R$ 280.366,71** na coluna nominal —
 *   R$ 147.015,35 na TOP LIFE I e R$ 133.351,36 na II. Sem este descarte, esse valor entraria como
 *   pagamento e apagaria a cobrança de 2.094 boletos.
 *   🔑 Este docblock afirmou **R$ 2.045.780** até 03/08. O número não se reproduz em arquivo nenhum
 *   (o maior valor de qualquer coluna dos dois é R$ 429.654,58) e sobreviveu porque ninguém tinha
 *   como recalculá-lo sem abrir a planilha. A regra sempre esteve certa; era a magnitude que estava
 *   errada, por um fator de 7. Ver spec §2.
 * - **Agrega por `(unidade, NN)`**: cada NN tem exatamente UMA data de recebimento e UMA competência
 *   (remedido em 03/08: zero grupos com mais de uma, nos dois arquivos), então o NN identifica o
 *   recebimento e as ~2,3 linhas de classe dele viram UM pagamento. A premissa não é suposta: se um
 *   export futuro trouxer duas datas ou duas competências no mesmo grupo, a linha é REJEITADA com
 *   motivo — fundir seria escolher uma data em silêncio e furar a idempotência, cuja chave é
 *   `(obrigação, data)`.
 * - **Classes → baldes do `Pagamento`**: `1.1`/`1.14` e o desconto `1.6` (sempre negativo) compõem a
 *   DÍVIDA; `1.4` (juros) e `1.5` (multa) os ENCARGOS; `1.15` os HONORÁRIOS. As raras (`1.12`, `1.19`,
 *   `1.22`, 11 linhas nos dois arquivos) caem na dívida. Classe DESCONHECIDA também cai na dívida, mas
 *   não em silêncio: toda classe fora do mapa acima é contada em `classesForaDoMapa` e o comando a
 *   imprime, para a conferência contra o quadro por classe de conta do relatório.
 *
 * Recusa com motivo: NN sem sacado, competência inválida, vencimento inválido, valor não numérico,
 * grupo com mais de uma data de recebimento ou competência, e recebimento cujo líquido não é positivo
 * (medido em 03/08: 1 caso, o NN `60082` da TOP LIFE II, taxa de R$ 170,00 anulada por um desconto de
 * R$ −170,00 — boleto perdoado, que não é receita).
 */
final class TopLifeReceitasAdapter
{
    private const COL_UNIDADE = 0;
    private const COL_SACADO = 1;
    private const COL_NN = 2;
    private const COL_CLASSE = 3;
    private const COL_COMPETENCIA = 4;
    private const COL_VENCIMENTO = 5;
    private const COL_RECEBIMENTO = 6;
    private const COL_VALOR_RECEBIDO = 8;
    private const COL_ACORDO = 9;

    /** Compõem a DÍVIDA: taxa de condomínio, energia e o desconto (negativo, que a abate). */
    private const CODIGOS_DIVIDA = ['1.1', '1.14', '1.6'];
    private const CODIGO_JUROS = '1.4';
    private const CODIGO_MULTA = '1.5';
    private const CODIGO_HONORARIO = '1.15';

    /** Marcador da contábil para "ainda não recebido" na coluna Recebimento. */
    private const MARCADOR_EM_ABERTO = '-';

    private const REGEX_ACORDO = '/^Acordo\s+(\d+)\s*-\s*Parc\.\s*(\d+)\/(\d+)$/i';

    /**
     * Códigos de classe que caíram no principal por OMISSÃO (não estão no mapa da spec §5) → nº de
     * linhas. Zerado a cada `ler()` para o adapter não acumular entre arquivos: o comando importa uma
     * carteira por execução, e um contador vazado faria a TOP LIFE II reportar as classes da I.
     *
     * @var array<string, int>
     */
    private array $classesForaDoMapa = [];

    public function ler(string $caminhoArquivo): ResultadoLeituraReceitas
    {
        $this->classesForaDoMapa = [];

        $reader = IOFactory::createReaderForFile($caminhoArquivo);
        $reader->setReadDataOnly(true);
        $linhas = $reader->load($caminhoArquivo)->getActiveSheet()->toArray(null, true, false, false);

        $inicio = $this->localizarInicioDados($linhas);
        if ($inicio === null) {
            return new ResultadoLeituraReceitas([], [], 0, 0);
        }

        $ignoradas = 0;
        $emAberto = 0;
        /** @var array<string, array{ordem: int, linhas: list<array<int, mixed>>}> $grupos */
        $grupos = [];
        $ordem = 0;

        for ($i = $inicio; $i < count($linhas); $i++) {
            $linha = $linhas[$i];
            $nn = trim((string) ($linha[self::COL_NN] ?? ''));
            $classe = trim((string) ($linha[self::COL_CLASSE] ?? ''));

            // Rodapé/total/metadado: não tem NN ou não tem classe de conta.
            if ($nn === '' || $classe === '') {
                ++$ignoradas;

                continue;
            }

            // ⚠️ Em aberto: contabilizado à parte porque é informação de negócio, não ruído de rodapé.
            $recebimento = trim((string) ($linha[self::COL_RECEBIMENTO] ?? ''));
            if ($recebimento === '' || $recebimento === self::MARCADOR_EM_ABERTO) {
                ++$emAberto;

                continue;
            }

            // Chave = Objeto (unidade principal) + NN, como na inadimplência: não assume NN globalmente
            // único, para o mesmo NN em duas unidades não fundir dinheiro de unidades diferentes.
            [$identificacao] = $this->separarUnidade(trim((string) ($linha[self::COL_UNIDADE] ?? '')));
            $chave = $identificacao . "\x1f" . $nn;
            if (!isset($grupos[$chave])) {
                $grupos[$chave] = ['ordem' => $ordem++, 'linhas' => []];
            }
            $grupos[$chave]['linhas'][] = $linha;
        }

        uasort($grupos, static fn (array $a, array $b): int => $a['ordem'] <=> $b['ordem']);

        $receitas = [];
        $rejeitadas = [];
        foreach ($grupos as $grupo) {
            $resultado = $this->montarReceita($grupo['linhas']);
            if ($resultado instanceof ReceitaImportavel) {
                $receitas[] = $resultado;

                continue;
            }
            $rejeitadas[] = $resultado;
        }

        ksort($this->classesForaDoMapa);

        return new ResultadoLeituraReceitas($receitas, $rejeitadas, $ignoradas, $emAberto, $this->classesForaDoMapa);
    }

    /**
     * @param list<array<int, mixed>> $linhas todas as linhas de classe do MESMO NN
     */
    private function montarReceita(array $linhas): ReceitaImportavel|LinhaRejeitada
    {
        $primeira = $linhas[0];
        $nn = trim((string) ($primeira[self::COL_NN] ?? ''));
        $unidadeRaw = trim((string) ($primeira[self::COL_UNIDADE] ?? ''));
        $sacado = trim((string) ($primeira[self::COL_SACADO] ?? ''));
        $dados = ['nn' => $nn, 'unidade' => $unidadeRaw, 'sacado' => $sacado];

        if ($sacado === '') {
            return new LinhaRejeitada($nn, 'Recebimento sem Sacado (devedor).', $dados);
        }

        $competencia = trim((string) ($primeira[self::COL_COMPETENCIA] ?? ''));
        if (preg_match('#^\d{2}/\d{4}$#', $competencia) !== 1) {
            return new LinhaRejeitada($nn, 'Competência ausente ou inválida (esperado MM/AAAA).', $dados);
        }

        $vencimento = $this->parseData((string) ($primeira[self::COL_VENCIMENTO] ?? ''));
        if ($vencimento === null) {
            return new LinhaRejeitada($nn, 'Recebimento sem vencimento válido.', $dados);
        }

        $recebimento = $this->parseData((string) ($primeira[self::COL_RECEBIMENTO] ?? ''));
        if ($recebimento === null) {
            return new LinhaRejeitada($nn, 'Data de recebimento inválida.', $dados);
        }

        // ⚠️ GUARDA DA PREMISSA (spec §6). Competência e data saem da PRIMEIRA linha do grupo e os
        // valores somam TODAS — o que só é correto porque cada `(unidade, NN)` tem uma competência e
        // uma data só. Isso foi remedido em 03/08 e vale (zero grupos divergentes nos dois arquivos),
        // mas é propriedade do EXPORT, não do formato: nesta fonte, três "fatos medidos" já caíram em
        // dois dias.
        //
        // Sem a guarda, um grupo com duas datas fundiria os valores num pagamento com a data da
        // primeira — em silêncio — e a chave de idempotência `(obrigação, data)` passaria a cobrir só
        // uma delas, deixando a outra entrar de novo a cada reimportação. Rejeitar com motivo põe o
        // caso na frente de quem importa, que é o único desfecho seguro num caminho de dinheiro.
        foreach ($linhas as $outra) {
            if (trim((string) ($outra[self::COL_COMPETENCIA] ?? '')) !== $competencia) {
                return new LinhaRejeitada($nn, 'Mesmo NN com mais de uma competência — não é possível decidir a qual boleto o recebimento pertence.', $dados);
            }

            $outraData = $this->parseData((string) ($outra[self::COL_RECEBIMENTO] ?? ''));
            if ($outraData === null || $outraData->format('Y-m-d') !== $recebimento->format('Y-m-d')) {
                return new LinhaRejeitada($nn, 'Mesmo NN com mais de uma data de recebimento — fundir escolheria uma data em silêncio e furaria a idempotência.', $dados);
            }
        }

        $divida = 0;
        $juros = 0;
        $multa = 0;
        $honorarios = 0;
        $detalhe = [];
        $acordo = null;
        /** @var array<string, int> $foraDoMapaNesteGrupo */
        $foraDoMapaNesteGrupo = [];

        foreach ($linhas as $linha) {
            $codigo = $this->codigoClasse((string) ($linha[self::COL_CLASSE] ?? ''));
            $valor = $this->parseCentavos($linha[self::COL_VALOR_RECEBIDO] ?? null);
            if ($valor === false) {
                return new LinhaRejeitada($nn, 'Valor recebido não numérico em uma das linhas.', $dados);
            }

            // O desconto (1.6) vem NEGATIVO e entra na dívida abatendo-a — sem tratamento próprio:
            // remedido em 03/08, a soma da coluna I por NN nunca fica negativa (mas DÁ ZERO em 1 caso,
            // que a guarda de líquido não-positivo lá embaixo rejeita).
            if (in_array($codigo, self::CODIGOS_DIVIDA, true)) {
                $divida += $valor;
            } elseif ($codigo === self::CODIGO_JUROS) {
                $juros += $valor;
            } elseif ($codigo === self::CODIGO_MULTA) {
                $multa += $valor;
            } elseif ($codigo === self::CODIGO_HONORARIO) {
                $honorarios += $valor;
            } else {
                // Classe FORA DO MAPA da spec §5 — as raras conhecidas (1.12 Água, 1.19 sobras,
                // 1.22 ajuste) e qualquer código novo que a contábil venha a criar. Entra no principal,
                // que é o balde certo para todas as conhecidas hoje, mas NÃO em silêncio: o código é
                // contado e o comando o imprime. Um balde escolhido por omissão que ninguém vê é como
                // dinheiro troca de balde sem ninguém notar — fecha no total e rateia errado.
                $divida += $valor;
                // Acumulado LOCAL, não direto no contador da instância: o grupo ainda pode ser
                // rejeitado nas guardas abaixo, e contar aqui faria o aviso do comando dizer "somadas
                // ao PRINCIPAL" sobre um valor que não foi somado a nada. Falso positivo num aviso de
                // conferência custa a confiança no aviso inteiro.
                $foraDoMapaNesteGrupo[$codigo] = ($foraDoMapaNesteGrupo[$codigo] ?? 0) + 1;
            }

            $acordo ??= $this->acordoDoRelatorio((string) ($linha[self::COL_ACORDO] ?? ''));
            $detalhe[] = ['classe' => trim((string) ($linha[self::COL_CLASSE] ?? '')), 'valor' => $valor];
        }

        if ($divida + $juros + $multa + $honorarios <= 0) {
            return new LinhaRejeitada($nn, 'Recebimento com valor líquido não positivo.', $dados);
        }

        // Só AQUI, passada a última guarda: o grupo vai virar receita de verdade, então as classes
        // fora do mapa dele de fato entraram no principal.
        foreach ($foraDoMapaNesteGrupo as $codigo => $qtd) {
            $this->classesForaDoMapa[$codigo] = ($this->classesForaDoMapa[$codigo] ?? 0) + $qtd;
        }

        [$identificacao, $metadata] = $this->separarUnidade($unidadeRaw);

        return new ReceitaImportavel(
            nn: $nn,
            objetoIdentificacao: $identificacao,
            unidadeMetadata: $metadata,
            sacadoNome: $sacado,
            competencia: $competencia,
            vencimento: $vencimento,
            recebimento: $recebimento,
            valorDividaCentavos: $divida,
            valorJurosCentavos: $juros,
            valorMultaCentavos: $multa,
            valorHonorariosCentavos: $honorarios,
            acordo: $acordo,
            linhas: $detalhe,
        );
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

    /** Código da classe antes do " - " (ex.: "1.15 - Honorário advocatício" → "1.15"). */
    private function codigoClasse(string $classe): string
    {
        $partes = explode(' - ', trim($classe), 2);

        return trim($partes[0]);
    }

    /**
     * "01-03A (05-03,06-01)" → ["01-03A", "05-03,06-01"]; sem parênteses → [unidade, null].
     *
     * @return array{0: string, 1: ?string}
     */
    private function separarUnidade(string $unidade): array
    {
        if (preg_match('/^(.*?)\s*\((.*)\)\s*$/', $unidade, $m) === 1) {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($unidade), null];
    }

    /** Reconhece "Acordo <N> - Parc. <p>/<t>"; qualquer outra coisa volta null (recebimento comum). */
    private function acordoDoRelatorio(string $valor): ?AcordoDoRelatorio
    {
        if (preg_match(self::REGEX_ACORDO, trim($valor), $m) !== 1) {
            return null;
        }

        return new AcordoDoRelatorio((int) $m[1], (int) $m[2], (int) $m[3]);
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
     * Converte para centavos inteiros. Vazio/null → 0; numérico → arredondado ×100; texto não
     * numérico → false (sinaliza rejeição do recebimento).
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
