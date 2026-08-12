<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Entity\Carteira;

/**
 * Descobre de qual carteira é um relatório da contabilidade (SPEC espelho §4.4).
 *
 * ⚠️ **Nenhum arquivo identifica a própria carteira no conteúdo.** O cabeçalho traz só o nome da
 * contabilidade, o título, o número de unidades inadimplentes, as taxas e os filtros. E há um
 * arquivo no acervo que não traz carteira nem no nome.
 *
 * **Atribuir a carteira errada produz 100% de "falta" + "sobra" na conferência, em silêncio** — é o
 * modo de falha mais perigoso da carga do histórico. Por isso a última regra é RECUSAR, nunca
 * adivinhar.
 *
 * INV-AC1: **a carga roda em DUAS passadas** — os arquivos identificáveis pelo nome primeiro, os
 * demais depois. Sem isso o nível 3 fica dependente da ordem de leitura: em ordem alfabética o
 * arquivo sem carteira seria lido antes dos nomeados, o espelho estaria vazio, e ele seria recusado
 * — contradizendo a promessa de carga reexecutável.
 */
final class AtribuidorDeCarteira
{
    /**
     * Como o nome de cada carteira aparece nos nomes de arquivo. Medido no acervo: o prefixo
     * canônico (`top_life_1_`…) cobre 19 dos 23; outros 3 trazem a carteira com grafia livre
     * (`TOPLIFE I_`, `TOPLIFE II_`, `toplifeII_`). A regra é "o NOME identifica a carteira", não
     * "o prefixo canônico" — com a leitura estrita a distribuição não fecha.
     *
     * As chaves são comparadas **da mais longa para a mais curta**: `toplife2` e `toplifeii`
     * precisam vencer `toplife1`/`toplifei`, senão "TOP LIFE II" seria lida como "TOP LIFE I".
     *
     * @var array<string, list<string>> nome canônico da carteira => apelidos normalizados
     */
    private const APELIDOS = [
        'TOP LIFE II' => ['top life 2', 'top life ii', 'toplife ii', 'toplifeii'],
        'TOP LIFE I' => ['top life 1', 'top life i', 'toplife i', 'toplifei'],
        'AMLI BR 060' => ['amli br 060', 'amli'],
    ];

    /** Alíquota de honorários que identifica uma carteira sozinha, em basis points. */
    private const HONORARIOS_EXCLUSIVOS = ['TOP LIFE I' => 2000];

    /** Contenção mínima do conjunto de unidades para atribuir pelo nível 3. */
    private const CONTENCAO_MINIMA = 0.90;

    /** Quantas vezes o primeiro colocado precisa superar o segundo. */
    private const VANTAGEM_MINIMA = 5.0;

    /**
     * Passada 1 — o nome do arquivo identifica a carteira.
     *
     * @param list<Carteira> $carteiras
     */
    public function porNome(string $arquivoNome, array $carteiras): ?Carteira
    {
        // Comparação por PALAVRA INTEIRA (os dois lados vêm cercados de espaço), nunca por pedaço de
        // texto. Medido: com busca por substring, `TOPLIFE I_Inadimplências…` normaliza para
        // `toplifeiinadimplencias…` — o `I` do nome colado ao `I` de "Inadimplências" forma um `ii`
        // que casa com o apelido da TOP LIFE **II**, e o arquivo vai para a carteira errada em
        // silêncio. Atribuição errada é o pior modo de falha desta carga: produz 100% de "falta" e
        // "sobra" na conferência, sem nenhum erro aparecer.
        $normalizado = $this->normalizar($arquivoNome);

        foreach (self::APELIDOS as $nomeCanonico => $apelidos) {
            foreach ($apelidos as $apelido) {
                if (str_contains($normalizado, ' ' . $apelido . ' ')) {
                    return $this->acharPorNome($nomeCanonico, $carteiras);
                }
            }
        }

        return null;
    }

    /**
     * Passada 2, nível 1 — a alíquota de honorários declarada no cabeçalho.
     *
     * Só desempata a TOP LIFE I (20%): TOP LIFE II e AMLI declaram ambas 15%, então este nível é
     * mudo para elas — e dizer isso é melhor do que fingir que decide.
     *
     * @param array<string, int>|null $configDeclarada
     * @param list<Carteira>          $carteiras
     */
    public function porHonorarios(?array $configDeclarada, array $carteiras): ?Carteira
    {
        $honorarios = $configDeclarada['honorarios'] ?? null;

        if ($honorarios === null) {
            return null;
        }

        foreach (self::HONORARIOS_EXCLUSIVOS as $nomeCanonico => $bp) {
            if ($honorarios === $bp) {
                return $this->acharPorNome($nomeCanonico, $carteiras);
            }
        }

        return null;
    }

    /**
     * Passada 2, nível 2 — sobreposição do conjunto de unidades contra o que já está no espelho.
     *
     * Atribui só com **contenção ≥ 90%** do conjunto do arquivo **e ≥ 5×** a do segundo colocado.
     * O limiar é numérico de propósito: "maioria dominante" é julgamento, e julgamento na hora de
     * codar vira decisão diferente a cada vez. *(No caso real medido a separação é 100% × 5,6% —
     * folgada em qualquer corte entre 10% e 100%.)*
     *
     * @param list<string>            $unidadesDoArquivo
     * @param array<int, list<string>> $unidadesPorCarteira carteiraId => unidades já espelhadas
     * @param list<Carteira>          $carteiras
     */
    public function porUnidades(array $unidadesDoArquivo, array $unidadesPorCarteira, array $carteiras): ?Carteira
    {
        $alvo = array_unique($unidadesDoArquivo);

        if ($alvo === [] || $unidadesPorCarteira === []) {
            return null;
        }

        $contencoes = [];

        foreach ($unidadesPorCarteira as $carteiraId => $unidades) {
            $comuns = count(array_intersect($alvo, $unidades));
            $contencoes[$carteiraId] = $comuns / count($alvo);
        }

        arsort($contencoes);

        $ids = array_keys($contencoes);
        $melhor = $contencoes[$ids[0]];
        $segundo = isset($ids[1]) ? $contencoes[$ids[1]] : 0.0;

        if ($melhor < self::CONTENCAO_MINIMA) {
            return null;
        }

        if ($segundo > 0.0 && $melhor < $segundo * self::VANTAGEM_MINIMA) {
            return null;
        }

        foreach ($carteiras as $carteira) {
            if ($carteira->getId() === $ids[0]) {
                return $carteira;
            }
        }

        return null;
    }

    /** @param list<Carteira> $carteiras */
    private function acharPorNome(string $nomeCanonico, array $carteiras): ?Carteira
    {
        $alvo = $this->normalizar($nomeCanonico);

        foreach ($carteiras as $carteira) {
            if ($this->normalizar($carteira->getNome() ?? '') === $alvo) {
                return $carteira;
            }
        }

        return null;
    }

    /**
     * Minúsculas, sem acento, com cada trecho não alfanumérico virando UM espaço — e cercado de
     * espaço nas pontas, para que a comparação por palavra inteira funcione também no começo e no
     * fim do nome. Preservar o separador é o que impede a colisão descrita em {@see self::porNome()}.
     */
    private function normalizar(string $texto): string
    {
        $semAcento = @iconv('UTF-8', 'ASCII//TRANSLIT', $texto);

        if ($semAcento === false) {
            $semAcento = $texto;
        }

        $tokens = (string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($semAcento));

        return ' ' . trim($tokens) . ' ';
    }
}
