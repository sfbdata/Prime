<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\DTO\ResultadoConferenciaEncargos;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\Importacao\IdentificacaoDaUnidade;
use App\Cobranca\Service\ResolvedorConfigEncargos;

/**
 * Responde: **o encargo GRAVADO no banco é um número que a nossa fórmula produz?**
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §17).
 *
 * É a régua que faltava na Fase 0. A conferência compara `valor_original`; a calibração compara a
 * nossa fórmula contra as colunas da planilha. **Nenhuma das duas lê o que está escrito em
 * `cobranca_obrigacao.juros/multa/correcao/honorarios`** — e é exatamente lá que a dupla contagem da
 * Fase 1 se materializa. Sem esta peça, rodar a calibração antes e depois do conserto daria o mesmo
 * número, e um "97% ao centavo" pós-conserto seria lido como prova sem provar nada.
 *
 * **Somente leitura.** Não escreve, não corrige snapshot divergente e não decide qual lado está certo.
 *
 * ⛔ INV-CE1 — **não compara o gravado com as colunas da planilha.** Medido em produção (13/08):
 * ZERO obrigações da TOP LIFE I têm `encargosAtualizadosEm` na data do lote; são 68 datas distintas,
 * de 2022 a 11/08/2026. Comparar as duas seria comparar contas feitas em dias diferentes — daria
 * ~100% de divergência e não significaria nada. A régua tem de ser independente da data.
 *
 * 🔑 INV-CE2 — **a régua é o gravado contra a fórmula NA DATA DO PRÓPRIO SNAPSHOT.** Cada obrigação
 * carrega `encargosAtualizadosEm`; rodar a `CalculadoraEncargos` sobre o `valorOriginal` naquela data
 * responde "alguém escreveu aqui um número que a fórmula não produz?" sem depender do relatório.
 *
 * INV-CE3 — **a config sai da cascata completa**, como na calibração: `ResolvedorConfigEncargos`, não
 * o preset da carteira. Na TOP LIFE I há 1.714 obrigações com honorário zerado por override, e
 * calibrar contra o preset as reportaria todas como divergência falsa.
 *
 * 🔑 INV-CE6 — **a assinatura tem de ler o lote que ESCREVEU a obrigação, não o último carregado.**
 * O `$relatorio` recebido define o UNIVERSO (carteira + `dadosAte`, spec §5.1); a COMPARAÇÃO é feita
 * contra o lote cuja emissão corresponde ao snapshot de cada obrigação.
 *
 * Por que isto não é detalhe: a assinatura testa **igualdade exata** contra `Σ coluna + H da linha`.
 * A coluna J (multa) é 2% fixo e **não anda** entre emissões, mas as colunas I (juros) e L (honorário)
 * andam todo dia. Contra o lote errado a igualdade falha **em silêncio** e a régua SUBCONTA — sem
 * erro, sem aviso, com cara de número certo.
 *
 * ⚠️ **Ordem de grandeza medida por SQL manual em 13/08/2026, NÃO por esta classe** — ela nunca rodou
 * contra produção, que está três commits atrás. A consulta reproduziu a assinatura fora do código e
 * **não aplica o INV-CE4** (coerência vence assinatura), então os totais abaixo são **teto**, não
 * fechamento. Servem para dimensionar o defeito, não para decidir dinheiro: quem decide é a lista que
 * esta régua produzir depois do deploy.
 *
 * | | contra 12/08 (último) | contra 11/08 (o que escreveu) |
 * |---|---:|---:|
 * | dívidas | 21 | ≤ 25 |
 * | juros | R$ 0,00 | R$ 624,72 |
 * | multa | R$ 804,83 | R$ 804,83 ← imune, é 2% fixo |
 * | honorário | R$ 362,75 | R$ 1.756,39 |
 *
 * ⚠️ **O casamento é por DATA, e a data não é um vínculo.** `encargosAtualizadosEm` é o MOMENTO em que
 * a importação rodou (`ImportarRelatorioCarteiraUseCase:169` carimba `new \DateTimeImmutable()` uma
 * vez por lote); não existe FK de obrigação para relatório. Casar os dois pela data só é válido porque
 * a importação roda no dia da emissão — verdade no canal restrito, mas **suposição, não invariante**.
 * Por isso **ambiguidade vira balde, nunca palpite**: sem lote na data, ou com mais de um, a obrigação
 * é INJULGÁVEL e sai contada em separado. Escolher "o mais próximo" devolveria número com cara de
 * certo, que é a classe de defeito que esta frente existe para matar.
 *
 * ⚠️ **A importação não é a única a carimbar `encargosAtualizadosEm`** — achado da revisão. Também
 * escrevem `CriarAcordoUseCase`, `ImportarAcordosDetalhadosUseCase` (ambos com a data do acordo),
 * `RegistrarObrigacaoUseCase`, `EditarObrigacaoUseCase`, `EditarConfiguracaoCasoUseCase` e
 * `EncargosVivos`. Se um desses carimbos cair na data de um lote, esta régua compara a obrigação
 * contra colunas que **não a escreveram** e reporta o resultado como conferido, sem sinal nenhum.
 *
 * Raio medido em produção (13/08/2026): **1 obrigação** — uma parcela do acordo 40, carimbada em
 * `2026-08-11 00:00:00` (data do acordo, não da importação), com os **quatro encargos zerados**. Como
 * a assinatura exige `hDaLinha > 0` e a igualdade com o gravado, ela precisaria de coluna negativa
 * para disparar: hoje não gera falso positivo. O defeito é real e cresce a cada lote carregado; a
 * correção durável é a obrigação registrar QUAL relatório a escreveu (FK), que é mudança de schema e
 * está anotada como dívida técnica na spec. Deliberadamente **não** há heurística sobre "o carimbo tem
 * hora": seria adivinhar sobre um dado que ninguém garantiu.
 */
final class ConferenciaDeEncargos
{
    private const PIORES = 20;

    /** As classes cujo valor a contabilidade lança como linha, e que a dupla contagem soma de novo. */
    private const CLASSES_DE_ENCARGO = ['1.4' => 'juros', '1.5' => 'multa', '1.15' => 'honorarios'];

    /** A classe cuja forma de acumulação é DIFERENTE das outras duas (ver INV-CE5). */
    private const CLASSE_HONORARIO = '1.15';

    private const CAMPOS = ['juros', 'multa', 'correcao', 'honorarios'];

    /**
     * Os campos que entram no `Obrigacao::valorExigivel()` — o saldo que o devedor deve.
     *
     * 🔴 **O honorário ENTRA, desde a spec `cobranca-honorario-no-total.md`.** Até ela, esta constante
     * era `['juros', 'multa', 'correcao']` e o docblock dizia "o honorário fica DE FORA" — verdade sob
     * a INV-E2, que aquela spec **revogou**. Deixar a régua com a definição velha faria o instrumento
     * que mede o espelho medir com a régua que o espelho abandonou: honorário duplicado apareceria
     * como "fora do saldo" na lista que o dono aprova, subestimando o impacto justamente na decisão
     * de escrever.
     *
     * ⚠️ **Consequência assumida:** com isto, `CAMPOS_NO_SALDO === CAMPOS` e `duplicadoForaDoSaldo`
     * passa a ser sempre 0. A separação vira degenerada de propósito — ela continua no relatório
     * porque a soma das duas ainda tem de fechar com o total duplicado (invariante conferido em
     * `ResultadoConferenciaEncargos::contasFecham`), e porque apagar coluna de relatório é outra fatia.
     */
    private const CAMPOS_NO_SALDO = ['juros', 'multa', 'correcao', 'honorarios'];

    public function __construct(
        private readonly AgrupadorDeBoletos $agrupador,
        private readonly ObrigacaoRepository $obrigacoes,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
        private readonly RelatorioImportadoRepository $relatorios,
    ) {
    }

    public function conferir(RelatorioImportado $relatorio): ResultadoConferenciaEncargos
    {
        $carteira = $relatorio->getCarteira();
        $dadosAte = $relatorio->getDadosAte();

        if ($carteira === null || $dadosAte === null) {
            throw new \LogicException(
                'Lote sem carteira ou sem a data "Inadimplência até" — sem ela não há universo a conferir.'
            );
        }

        // INV-CE6: os lotes indexados pela emissão, para achar o que escreveu cada obrigação.
        $lotesPorEmissao = $this->lotesPorEmissao($carteira);

        $assinaturaAvaliada = 0;
        $semPar = 0;
        $coerentes = 0;
        $duplaContagem = 0;
        $divergentes = 0;
        $diferenca = 0;
        $duplicado = 0;
        $porCampo = [];
        $piores = [];
        $duplicadas = [];

        // Partição por lote ANTES de somar qualquer relatório. É o que permite carregar as somas de um
        // lote, gastá-las e DESCARTAR antes do próximo: o pico de memória vira o de um lote, não o da
        // soma de todos. A versão com cache (`$somasPorLote[$id] ??= ...`) não tinha teto nenhum, e o
        // espelho acumula um lote por dia — o `calibrar` já estourou 128M nesta carteira.
        [$porLote, $lotesUsados, $semLote] = $this->particionarPorLote($carteira, $dadosAte, $lotesPorEmissao);

        $injulgaveis = count($semLote);
        $universo = $injulgaveis;

        // As injulgáveis primeiro, sem lote nenhum carregado: só a dimensão da coerência.
        // As demais, um lote por vez.
        $blocos = [[null, $semLote]];

        foreach ($porLote as $idDoLote => $obrigacoesDoLote) {
            $universo += count($obrigacoesDoLote);
            $blocos[] = [$idDoLote, $obrigacoesDoLote];
        }

        foreach ($blocos as [$idDoLote, $obrigacoesDoBloco]) {
            if ($obrigacoesDoBloco === []) {
                continue;
            }

            $somas = $idDoLote === null ? [] : $this->somasDoRelatorio($lotesUsados[$idDoLote]);

            foreach ($obrigacoesDoBloco as $obrigacao) {
                // 🔑 INV-CE7 — a COERÊNCIA roda SEMPRE, porque não depende de lote nenhum (INV-CE2: é o
                // gravado contra a fórmula na data do próprio snapshot). Só a ASSINATURA precisa das
                // colunas, e só ela fica suspensa quando não há lote.
                //
                // A primeira versão deste conserto pulava a obrigação inteira no `injulgável`, e com
                // isso jogava fora a única leitura que ainda era possível fazer nela. Medido pela
                // revisão no dev: R$ 150.262,17 de encargo gravado saíam sem conferência alguma, com a
                // régua imprimindo "diferença total: R$ 0,00". Perder informação que não dependia do
                // lote foi erro gratuito.
                $gravado = $this->gravado($obrigacao);
                $pelaFormula = $this->pelaFormula($obrigacao);
                $coerente = $gravado === $pelaFormula;

                $grupo = $idDoLote === null ? null : ($somas[$this->chaveDa($obrigacao)] ?? null);

                if ($idDoLote !== null) {
                    if ($grupo === null) {
                        // A obrigação existe no sistema e o lote que a escreveu não a cobra. Aqui ela
                        // só não tem contra o que ser lida — a sobra é matéria da CONFERÊNCIA.
                        ++$semPar;
                    } else {
                        ++$assinaturaAvaliada;
                    }
                }

                $duplicadoAqui = $grupo === null
                    ? []
                    : $this->duplaContagemPorCampo($gravado, $grupo, $obrigacao);

                // INV-CE4 — a COERÊNCIA vence a assinatura, e a ordem é decisão, não acaso. Se a
                // fórmula produz exatamente o que está gravado, o número tem procedência: uma
                // coincidência com a forma da dupla contagem não o torna dinheiro duplicado. Inverter a
                // ordem faria a régua acusar snapshot legítimo, que é o falso positivo caro — o dono
                // levaria à contabilidade um defeito que não existe.
                if ($coerente) {
                    ++$coerentes;

                    continue;
                }

                if ($duplicadoAqui !== []) {
                    ++$duplaContagem;

                    $noSaldo = 0;

                    foreach ($duplicadoAqui as $campo => $centavos) {
                        $duplicado += $centavos;
                        $porCampo[$campo] = ($porCampo[$campo] ?? 0) + $centavos;

                        if (in_array($campo, self::CAMPOS_NO_SALDO, true)) {
                            $noSaldo += $centavos;
                        }
                    }

                    // A LISTA DA RECONCILIAÇÃO (INV-CE8). Sem corte e com o lote de cada linha: é ela
                    // que o dono aprova antes de qualquer escrita, e é contra ela que a reconciliação
                    // roda. O `piores`, logo abaixo, NÃO serve para isso — está cortado em 20 e
                    // ordenado pela diferença contra a fórmula, que é outra pergunta.
                    $duplicadas[] = [
                        'obrigacaoId' => $obrigacao->getId(),
                        'unidade' => $this->unidadeDe($obrigacao),
                        'referencia' => $obrigacao->getReferenciaExterna(),
                        'competencia' => $obrigacao->getCompetencia(),
                        'loteId' => $idDoLote,
                        'loteEmitidoEm' => $lotesUsados[$idDoLote]?->getEmitidoEm(),
                        'duplicadoPorCampo' => $duplicadoAqui,
                        'duplicadoNoSaldo' => $noSaldo,
                        'duplicadoForaDoSaldo' => array_sum($duplicadoAqui) - $noSaldo,
                        // 🔑 INV-CE9 — o que a reconciliação deve GRAVAR, calculado aqui e não lá.
                        // A régua já tem o `$grupo` em mãos; deixar a reconciliação re-derivar seria
                        // uma segunda cópia da regra de dinheiro (D10), e é justamente onde a
                        // assimetria do honorário morde: ver `corrigidoPorCampo()`.
                        'corrigidoPorCampo' => $this->corrigidoPorCampo($duplicadoAqui, $grupo),
                    ];
                } else {
                    // Inclui a obrigação cuja assinatura ficou SUSPENSA por falta de lote: ela tem
                    // número sem procedência (a fórmula não o reproduz) e isso é verdade sem lote
                    // nenhum. O que não se pode dizer dela é que duplicou — faltam as colunas.
                    ++$divergentes;
                }

                foreach (self::CAMPOS as $campo) {
                    $delta = $gravado[$campo] - $pelaFormula[$campo];

                    if ($delta === 0) {
                        continue;
                    }

                    $diferenca += abs($delta);

                    $piores[] = [
                        'unidade' => $this->unidadeDe($obrigacao),
                        'referencia' => $obrigacao->getReferenciaExterna(),
                        'campo' => $campo,
                        'gravado' => $gravado[$campo],
                        'pelaFormula' => $pelaFormula[$campo],
                        'diferenca' => $delta,
                        // Sem esta coluna a lista mistura os dois baldes e quem lê não sabe o que é
                        // dinheiro duplicado e o que é snapshot velho — foi achado da revisão.
                        'duplicado' => $duplicadoAqui[$campo] ?? 0,
                        'ehParcelaDeAcordo' => $obrigacao->getAcordoOrigem() !== null,
                    ];
                }
            }

            // O ponto do bloco: as somas deste lote morrem aqui.
            unset($somas);
        }

        usort($piores, static fn (array $a, array $b): int => abs($b['diferenca']) <=> abs($a['diferenca']));

        // Ordenada pelo DUPLICADO — a pergunta da reconciliação é "quanto tem de dinheiro contado duas
        // vezes aqui", não "quanto este snapshot se afastou da fórmula".
        usort(
            $duplicadas,
            static fn (array $a, array $b): int => array_sum($b['duplicadoPorCampo']) <=> array_sum($a['duplicadoPorCampo']),
        );

        return new ResultadoConferenciaEncargos(
            carteira: $carteira->getNome() ?? '?',
            dadosAte: $dadosAte,
            universo: $universo,
            assinaturaAvaliada: $assinaturaAvaliada,
            semParNoRelatorio: $semPar,
            coerentes: $coerentes,
            comDuplaContagem: $duplaContagem,
            divergentes: $divergentes,
            injulgaveis: $injulgaveis,
            diferencaEmCentavos: $diferenca,
            duplicadoEmCentavos: $duplicado,
            duplicadoPorCampo: $porCampo,
            piores: array_slice($piores, 0, self::PIORES),
            duplicadas: $duplicadas,
        );
    }

    /**
     * Separa o universo em "obrigações de cada lote" e "sem lote que as tenha escrito", **sem somar
     * relatório nenhum** — é o que permite depois carregar um lote por vez e descartá-lo (INV-CE6).
     *
     * @param array<string, ?RelatorioImportado> $lotesPorEmissao
     *
     * @return array{array<int, list<Obrigacao>>, array<int, RelatorioImportado>, list<Obrigacao>}
     */
    private function particionarPorLote(
        Carteira $carteira,
        \DateTimeImmutable $dadosAte,
        array $lotesPorEmissao,
    ): array {
        $porLote = [];
        $lotesUsados = [];
        $semLote = [];

        foreach ($this->obrigacoes->emAbertoDaCarteiraAteComEncargos($carteira, $dadosAte) as $obrigacao) {
            // INV-CE6 — o lote a comparar é o da emissão que escreveu ESTA obrigação. Sem ele não há
            // contra o que ler a assinatura, e chutar o mais próximo produziria acusação (ou
            // absolvição) sem lastro: balde próprio, contado e reportado.
            $lote = $this->loteQueEscreveu($obrigacao, $lotesPorEmissao);

            if ($lote === null) {
                $semLote[] = $obrigacao;

                continue;
            }

            $id = $lote->getId();

            if ($id === null) {
                $semLote[] = $obrigacao;

                continue;
            }

            $lotesUsados[$id] = $lote;
            $porLote[$id][] = $obrigacao;
        }

        return [$porLote, $lotesUsados, $semLote];
    }

    /**
     * Os lotes da carteira indexados pela data de EMISSÃO (`emitidoEm`), para o casamento do INV-CE6.
     *
     * ⚠️ **`emitidoEm` e `dadosAte` NÃO são sinônimos** — achado da revisão. `dadosAte` é o
     * "Inadimplência até" (a data de corte dos números); `emitidoEm` é o rodapé "Emissão" (quando o
     * arquivo foi gerado). No dado de dev há lote com corte em 21/07 e emissão em 22/07. A âncora é a
     * **emissão**, porque é sobre o arquivo emitido que a importação roda, e é a importação que carimba
     * `encargosAtualizadosEm`. Em produção as duas coincidem nos dois lotes existentes, então hoje a
     * escolha não muda número nenhum — o que a torna uma decisão de significado, não de resultado.
     *
     * Data com MAIS DE UM lote fica marcada como ambígua (`null`) em vez de eleger um: duas emissões
     * do mesmo dia têm colunas diferentes, e escolher a errada devolve o mesmo falso silêncio que este
     * invariante existe para fechar. Lote sem `emitidoEm` fica de fora — não dá para casar por uma data
     * que não existe.
     *
     * @return array<string, ?RelatorioImportado>
     */
    private function lotesPorEmissao(Carteira $carteira): array
    {
        $porData = [];

        foreach ($this->relatorios->todosDaCarteira($carteira) as $lote) {
            $emitidoEm = $lote->getEmitidoEm();

            if ($emitidoEm === null) {
                continue;
            }

            $data = $emitidoEm->format('Y-m-d');
            // `array_key_exists` e não `isset`: a chave ambígua guarda `null`, e `isset` a daria como
            // inexistente — o terceiro lote da mesma data voltaria a eleger um vencedor em silêncio.
            $porData[$data] = array_key_exists($data, $porData) ? null : $lote;
        }

        return $porData;
    }

    /**
     * O lote cuja emissão corresponde ao snapshot da obrigação — `null` quando não dá para saber.
     *
     * São TRÊS causas distintas de `null`, todas legítimas e todas caindo no mesmo balde: obrigação
     * sem carimbo, carimbo em data sem lote no espelho (medido em produção: **99 obrigações no
     * universo de 3.672 desta régua**, 2,7%), e data com mais de um lote. Distingui-las aqui só
     * produziria contagem mais fina de uma coisa que, em qualquer dos três casos, esta régua **não
     * sabe julgar**.
     *
     * @param array<string, ?RelatorioImportado> $lotesPorEmissao
     */
    private function loteQueEscreveu(Obrigacao $obrigacao, array $lotesPorEmissao): ?RelatorioImportado
    {
        $snapshot = $obrigacao->getEncargosAtualizadosEm();

        if ($snapshot === null) {
            return null;
        }

        return $lotesPorEmissao[$snapshot->format('Y-m-d')] ?? null;
    }

    /**
     * A ASSINATURA do defeito 2 — a forma EXATA do que o adapter escreve, campo a campo.
     *
     * 🔑 INV-CE5 — **duas condições, e faltando qualquer uma não há dinheiro duplicado.**
     *
     * 1. **O H tem de estar DENTRO do `valorOriginal`.** Duplicar é o mesmo dinheiro em dois lugares;
     *    se o principal não absorveu a linha de encargo, o H aparece uma vez só e está certo. Medido:
     *    `valorOriginal = Σ coluna Valor de TODAS as classes` acontece só no ramo do acordo
     *    (`ImportarRelatorioCarteiraUseCase:514`); no boleto comum é `principalCentavos`, e somar o H
     *    da linha `1.4` ao juros é o comportamento **documentado e correto** do adapter (INV-E1).
     *    Sem esta guarda a régua acusava o único boleto comum com linha de encargo da TOP LIFE I —
     *    R$ 2,90 de acusação falsa.
     * 2. **A igualdade, não a diferença.** "Maior que o esperado" acusaria snapshot velho.
     *
     * ⚠️ **A forma do honorário NÃO é a dos outros dois, e assumir simetria foi um defeito real.**
     * O adapter (`TopLifeInadimplenciaAdapter:186`) faz
     * `$honorarios += $codigo === '1.15' ? $valor : $hono` — na linha `1.15` ele **troca** a coluna L
     * pelo H, enquanto em juros/multa ele **soma** o H por cima de todas as colunas
     * (`:180-185`). Logo o gravado é `Σ_{linhas ≠ 1.15} L + Σ_{1.15} H`, e testar
     * `Σ_{todas} L + Σ_{1.15} H` nunca casa quando a própria linha `1.15` traz valor na coluna L.
     * Medido em produção: **26 boletos** nessa situação, e a forma errada escondia **3 dívidas /
     * R$ 173,28** de dinheiro realmente duplicado.
     *
     * @param array{juros:int, multa:int, correcao:int, honorarios:int} $gravado
     * @param array{juros:int, multa:int, correcao:int, honorarios:int, somaColunaValor:int,
     *              honorariosForaDaLinha115:int, encargoDaLinha: array<string,int>} $grupo
     *
     * @return array<string, int> campo => centavos duplicados (vazio quando não há)
     */
    private function duplaContagemPorCampo(array $gravado, array $grupo, Obrigacao $obrigacao): array
    {
        // INV-CE5, condição 1: sem o H dentro do principal, não existe "duas vezes".
        if ($obrigacao->getValorOriginal() !== $grupo['somaColunaValor']) {
            return [];
        }

        $duplicado = [];

        foreach (self::CLASSES_DE_ENCARGO as $classe => $campo) {
            $hDaLinha = $grupo['encargoDaLinha'][$campo] ?? 0;

            if ($hDaLinha <= 0) {
                continue;
            }

            // O honorário tem forma própria; ver o aviso do docblock.
            $comoOAdapterEscreve = $classe === self::CLASSE_HONORARIO
                ? $grupo['honorariosForaDaLinha115'] + $hDaLinha
                : $grupo[$campo] + $hDaLinha;

            if ($gravado[$campo] === $comoOAdapterEscreve) {
                $duplicado[$campo] = $hDaLinha;
            }
        }

        return $duplicado;
    }

    /**
     * O valor que cada campo MARCADO deveria ter — o encargo das colunas, que é exatamente o que a
     * importação corrigida grava numa parcela de acordo (`ImportarRelatorioCarteiraUseCase`, ramo do
     * acordo). Depois da reconciliação, reimportar o mesmo lote não pode mexer em nada.
     *
     * 🔑 **NÃO é `gravado − duplicado`, e essa diferença é a armadilha desta frente.** Em juros e multa
     * as duas contas coincidem; **no honorário, não**. O adapter, na linha `1.15`, **troca** a coluna L
     * pelo Valor em vez de somar (`TopLifeInadimplenciaAdapter:200`), então subtrair o duplicado
     * devolveria `Σ_{≠1.15} L` e **perderia a coluna L da própria linha de honorário**. O valor certo é
     * `Σ_{todas} L`, que é o que `$grupo['honorarios']` carrega (o {@see AgrupadorDeBoletos} soma a
     * coluna L de toda linha, sem exceção). Medido: nas 21 do recorte antigo a diferença era R$ 31,19.
     *
     * ⛔ **A correção NUNCA toca a correção monetária:** nenhuma classe de linha vira correção, logo ela
     * nunca é marcada — e campo não marcado não entra neste array.
     *
     * @param array<string, int> $duplicadoPorCampo campos marcados pela assinatura
     * @param array{juros:int, multa:int, correcao:int, honorarios:int, ...} $grupo
     *
     * @return array<string, int> campo => centavos a gravar
     */
    private function corrigidoPorCampo(array $duplicadoPorCampo, array $grupo): array
    {
        $corrigido = [];

        foreach (array_keys($duplicadoPorCampo) as $campo) {
            $corrigido[$campo] = $grupo[$campo];
        }

        return $corrigido;
    }

    /** @return array{juros:int, multa:int, correcao:int, honorarios:int} */
    private function gravado(Obrigacao $obrigacao): array
    {
        return [
            'juros' => $obrigacao->getJuros(),
            'multa' => $obrigacao->getMulta(),
            'correcao' => $obrigacao->getCorrecao(),
            'honorarios' => $obrigacao->getHonorarios(),
        ];
    }

    /**
     * O que a fórmula produz na data do PRÓPRIO snapshot (INV-CE2). Sem data carimbada não há contra o
     * que ler o gravado — o resultado degrada para zeros, e a obrigação cai em `divergente` se tiver
     * encargo, o que é a leitura honesta: existe número gravado sem procedência. Ela cai **também** em
     * `injulgável`, porque sem carimbo não há como achar o lote (INV-CE6) — os dois baldes são
     * dimensões diferentes e valem ao mesmo tempo.
     *
     * @return array{juros:int, multa:int, correcao:int, honorarios:int}
     */
    private function pelaFormula(Obrigacao $obrigacao): array
    {
        $snapshot = $obrigacao->getEncargosAtualizadosEm();

        if ($snapshot === null) {
            return ['juros' => 0, 'multa' => 0, 'correcao' => 0, 'honorarios' => 0];
        }

        return $this->calculadora->calcular(
            $obrigacao->getValorOriginal(),
            $obrigacao->getVencimentoOriginal(),
            $this->resolvedor->resolver($obrigacao),
            $snapshot,
        );
    }

    /**
     * As somas do relatório por boleto, mais o H das linhas de encargo — que é o insumo da assinatura.
     *
     * Reusa o {@see AgrupadorDeBoletos} para a chave e para as colunas somadas (uma régua de
     * casamento só, INV-A2) e percorre as linhas de novo apenas para separar o H por classe, que é
     * informação que o agrupamento por boleto não preserva.
     *
     * @return array<string, array{juros:int, multa:int, correcao:int, honorarios:int,
     *                             encargoDaLinha: array<string,int>}>
     */
    private function somasDoRelatorio(RelatorioImportado $relatorio): array
    {
        $somas = [];

        foreach ($this->agrupador->agrupar($relatorio) as $chave => $grupo) {
            $somas[$chave] = [
                'juros' => $grupo['juros'],
                'multa' => $grupo['multa'],
                'correcao' => $grupo['correcao'],
                'honorarios' => $grupo['honorarios'],
                'somaColunaValor' => 0,
                'honorariosForaDaLinha115' => $grupo['honorarios'],
                'encargoDaLinha' => [],
            ];
        }

        foreach ($this->agrupador->linhasComChave($relatorio) as ['chave' => $chave, 'linha' => $linha]) {
            if (!isset($somas[$chave])) {
                continue;
            }

            // Σ da coluna Valor de TODAS as classes — é contra isto que se sabe se o `valorOriginal`
            // absorveu as linhas de encargo (INV-CE5, condição 1). O `principal` do Agrupador não
            // serve: ele já aplicou o ramo, e no boleto comum exclui justamente essas linhas.
            $somas[$chave]['somaColunaValor'] += $linha['valor'] ?? 0;

            $codigo = AgrupadorDeBoletos::codigoDaClasse($linha['classe']);

            if ($codigo === self::CLASSE_HONORARIO) {
                // O adapter DESCARTA a coluna L da própria linha 1.15 e põe o H no lugar.
                $somas[$chave]['honorariosForaDaLinha115'] -= $linha['honorarios'] ?? 0;
            }

            $campo = self::CLASSES_DE_ENCARGO[$codigo] ?? null;

            if ($campo === null) {
                continue;
            }

            $somas[$chave]['encargoDaLinha'][$campo] =
                ($somas[$chave]['encargoDaLinha'][$campo] ?? 0) + ($linha['valor'] ?? 0);
        }

        return $somas;
    }

    private function chaveDa(Obrigacao $obrigacao): string
    {
        return AgrupadorDeBoletos::chave(
            $this->unidadeDe($obrigacao),
            $obrigacao->getReferenciaExterna() ?? '',
            $obrigacao->getCompetencia(),
        );
    }

    private function unidadeDe(Obrigacao $obrigacao): string
    {
        $identificacao = $obrigacao->getCaso()?->getObjeto()?->getIdentificacao() ?? '';

        [$limpa] = IdentificacaoDaUnidade::separar($identificacao);

        return $limpa;
    }
}
