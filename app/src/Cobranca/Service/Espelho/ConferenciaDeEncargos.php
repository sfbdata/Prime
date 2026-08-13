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
            $duplicadoAqui = $this->duplaContagemEmCentavos($gravado, $grupo);

            // INV-CE4 — a COERÊNCIA vence a assinatura, e a ordem é decisão, não acaso. Se a fórmula
            // produz exatamente o que está gravado, o número tem procedência: uma coincidência com a
            // forma da dupla contagem não o torna dinheiro duplicado. Inverter a ordem faria a régua
            // acusar snapshot legítimo, que é o falso positivo caro — o dono levaria à contabilidade
            // um defeito que não existe.
            if ($gravado === $pelaFormula) {
                ++$coerentes;

                continue;
            }

            if ($duplicadoAqui > 0) {
                ++$duplaContagem;
                $duplicado += $duplicadoAqui;
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
            piores: array_slice($piores, 0, self::PIORES),
        );
    }

    /**
     * A ASSINATURA do defeito 2, e ela é exata: o gravado é a coluna do relatório **mais** o H da
     * linha de encargo correspondente. Não é "está maior que o esperado" — é a igualdade que só
     * acontece quando `materializarEncargosImportados` somou o mesmo dinheiro duas vezes.
     *
     * Devolve quanto foi duplicado, ou 0 quando a assinatura não bate. Reconhecer por igualdade, e não
     * por diferença, é o que impede a régua de acusar snapshot velho como se fosse dupla contagem.
     *
     * @param array{juros:int, multa:int, correcao:int, honorarios:int} $gravado
     * @param array{juros:int, multa:int, correcao:int, honorarios:int, encargoDaLinha: array<string,int>} $grupo
     */
    private function duplaContagemEmCentavos(array $gravado, array $grupo): int
    {
        $duplicado = 0;

        foreach (self::CLASSES_DE_ENCARGO as $campo) {
            $hDaLinha = $grupo['encargoDaLinha'][$campo] ?? 0;

            if ($hDaLinha > 0 && $gravado[$campo] === $grupo[$campo] + $hDaLinha) {
                $duplicado += $hDaLinha;
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
                'encargoDaLinha' => [],
            ];
        }

        foreach ($this->agrupador->linhasComChave($relatorio) as ['chave' => $chave, 'linha' => $linha]) {
            $codigo = $this->codigoDaClasse($linha['classe']);
            $campo = self::CLASSES_DE_ENCARGO[$codigo] ?? null;

            if ($campo === null || !isset($somas[$chave])) {
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
