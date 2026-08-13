<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\DTO\ResultadoConferenciaEncargos;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Repository\ObrigacaoRepository;
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
 */
final class ConferenciaDeEncargos
{
    private const PIORES = 20;

    /** As classes cujo valor a contabilidade lança como linha, e que a dupla contagem soma de novo. */
    private const CLASSES_DE_ENCARGO = ['1.4' => 'juros', '1.5' => 'multa', '1.15' => 'honorarios'];

    /** A classe cuja forma de acumulação é DIFERENTE das outras duas (ver INV-CE5). */
    private const CLASSE_HONORARIO = '1.15';

    private const CAMPOS = ['juros', 'multa', 'correcao', 'honorarios'];

    public function __construct(
        private readonly AgrupadorDeBoletos $agrupador,
        private readonly ObrigacaoRepository $obrigacoes,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
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

        $doRelatorio = $this->somasDoRelatorio($relatorio);

        $conferidos = 0;
        $semPar = 0;
        $coerentes = 0;
        $duplaContagem = 0;
        $divergentes = 0;
        $diferenca = 0;
        $duplicado = 0;
        $porCampo = [];
        $piores = [];

        foreach ($this->obrigacoes->emAbertoDaCarteiraAteComEncargos($carteira, $dadosAte) as $obrigacao) {
            $chave = $this->chaveDa($obrigacao);
            $grupo = $doRelatorio[$chave] ?? null;

            if ($grupo === null) {
                // A obrigação existe no sistema e o relatório não a cobra. Isso é matéria da
                // CONFERÊNCIA (balde "sobra no sistema"); aqui ela só não tem contra o que ser lida.
                ++$semPar;

                continue;
            }

            ++$conferidos;

            $gravado = $this->gravado($obrigacao);
            $pelaFormula = $this->pelaFormula($obrigacao);
            $duplicadoAqui = $this->duplaContagemPorCampo($gravado, $grupo, $obrigacao);

            // INV-CE4 — a COERÊNCIA vence a assinatura, e a ordem é decisão, não acaso. Se a fórmula
            // produz exatamente o que está gravado, o número tem procedência: uma coincidência com a
            // forma da dupla contagem não o torna dinheiro duplicado. Inverter a ordem faria a régua
            // acusar snapshot legítimo, que é o falso positivo caro — o dono levaria à contabilidade
            // um defeito que não existe.
            if ($gravado === $pelaFormula) {
                ++$coerentes;

                continue;
            }

            if ($duplicadoAqui !== []) {
                ++$duplaContagem;

                foreach ($duplicadoAqui as $campo => $centavos) {
                    $duplicado += $centavos;
                    $porCampo[$campo] = ($porCampo[$campo] ?? 0) + $centavos;
                }
            } else {
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

        usort($piores, static fn (array $a, array $b): int => abs($b['diferenca']) <=> abs($a['diferenca']));

        return new ResultadoConferenciaEncargos(
            carteira: $carteira->getNome() ?? '?',
            dadosAte: $dadosAte,
            conferidos: $conferidos,
            semParNoRelatorio: $semPar,
            coerentes: $coerentes,
            comDuplaContagem: $duplaContagem,
            divergentes: $divergentes,
            diferencaEmCentavos: $diferenca,
            duplicadoEmCentavos: $duplicado,
            duplicadoPorCampo: $porCampo,
            piores: array_slice($piores, 0, self::PIORES),
        );
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
     * encargo, o que é a leitura honesta: existe número gravado sem procedência.
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

    /** `1.4 - Juros` → `1.4`. Compara o código INTEIRO: `1.1` não pode casar `1.15`. */
    private function codigoDaClasse(?string $classe): string
    {
        if ($classe === null) {
            return '';
        }

        $partes = preg_split('/\s*-\s*/', trim($classe), 2);

        return $partes === false ? '' : trim($partes[0]);
    }
}
