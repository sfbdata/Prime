<?php

declare(strict_types=1);

namespace App\Master\Fontes;

/**
 * Lê as 3 tabelas do Quadro da Advocacia do Conselho Federal da OAB
 * (https://www.oab.org.br/institucionalconselhofederal/quadroadvogados), que vêm renderizadas no
 * HTML da página: quantitativo, gênero e faixa etária, por seccional.
 *
 * Formato de saída por UF (o mesmo do `OAB0` do desenho, `mapa-dados.js`), 14 posições:
 *   0 advogados · 1 estagiários · 2 suplementares · 3 consultores estrangeiros ·
 *   4 advogadas · 5 advogados (masculino) ·
 *   6/7 até 25 anos F/M · 8/9 26 a 40 F/M · 10/11 41 a 59 F/M · 12/13 60 ou mais F/M
 * Posição que a página não trouxe fica nula. O retrato só é aceito se fechar com os totais da
 * própria página (ver {@see validar()}).
 */
final class LeitorQuadroOab
{
    /**
     * @return array{uf: array<string, list<int|null>>, totalAdvogados: ?int}
     *
     * @throws FonteIndisponivel quando a página não tem o quantitativo das 27 seccionais
     */
    public function ler(string $html): array
    {
        $xpath = self::xpath($html);
        $saida = [];

        foreach ($xpath->query("//div[@id='divTotal']//tbody/tr") ?: [] as $tr) {
            [$uf, $numeros] = self::linha($xpath, $tr);
            if ($uf !== null && count($numeros) >= 4) {
                $saida[$uf] ??= array_fill(0, 14, null);
                for ($i = 0; $i < 4; $i++) {
                    $saida[$uf][$i] = $numeros[$i];
                }
            }
        }

        foreach ($xpath->query("//div[@id='divTotalPorSexo']//tbody/tr") ?: [] as $tr) {
            [$uf, $numeros] = self::linha($xpath, $tr);
            if ($uf !== null && count($numeros) >= 2) {
                $saida[$uf] ??= array_fill(0, 14, null);
                $saida[$uf][4] = $numeros[0];
                $saida[$uf][5] = $numeros[1];
            }
        }

        $ufAtual = null;
        foreach ($xpath->query("//div[@id='divTotalFaixaEtaria']//tbody/tr") ?: [] as $tr) {
            $th = $xpath->query('./th', $tr)?->item(0);
            if ($th !== null) {
                $ufAtual = self::sigla($th->textContent);
            }
            $tds = $xpath->query('./td', $tr);
            if ($ufAtual === null || $tds === false || $tds->length < 3) {
                continue;
            }
            $faixa = self::indiceDaFaixa((string) $tds->item(0)?->textContent);
            if ($faixa === null) {
                continue;
            }
            $saida[$ufAtual] ??= array_fill(0, 14, null);
            $saida[$ufAtual][6 + $faixa * 2] = self::numero((string) $tds->item(1)?->textContent);
            $saida[$ufAtual][7 + $faixa * 2] = self::numero((string) $tds->item(2)?->textContent);
        }

        self::validar($xpath, $saida);
        ksort($saida);

        return ['uf' => $saida, 'totalAdvogados' => self::totalAdvogados($xpath)];
    }

    /**
     * Só aceita o retrato se ele fecha com a própria página: exatamente as 27 seccionais conhecidas,
     * advogados > 0 em todas, e a soma de cada coluna igual ao TOTAL do rodapé de cada tabela. Uma
     * coluna deslocada ou um layout novo da OAB faz a conta não fechar, e o retrato anterior fica.
     *
     * @param array<string, list<int|null>> $saida
     *
     * @throws FonteIndisponivel
     */
    private static function validar(\DOMXPath $xpath, array $saida): void
    {
        $siglas = array_keys($saida);
        sort($siglas);
        $esperadas = array_keys(ClienteIbge::CODIGO_UF);
        sort($esperadas);
        if ($siglas !== $esperadas) {
            throw new FonteIndisponivel('Quadro da Advocacia com seccionais diferentes das 27 UFs: ' . implode(',', array_diff($siglas, $esperadas) ?: array_diff($esperadas, $siglas)));
        }

        foreach ($saida as $uf => $valores) {
            if ($valores[0] === null || $valores[0] <= 0) {
                throw new FonteIndisponivel(sprintf('Quadro da Advocacia sem advogados em %s.', $uf));
            }
        }

        $soma = static fn (int $posicao): int => array_sum(array_map(static fn (array $v): int => (int) $v[$posicao], $saida));
        $conferir = static function (string $tabela, array $posicoes, array $totais) use ($soma): void {
            foreach ($posicoes as $i => $posicao) {
                if (!array_key_exists($i, $totais) || $totais[$i] === null || $soma($posicao) !== $totais[$i]) {
                    throw new FonteIndisponivel(sprintf('Quadro da Advocacia: a soma da tabela %s (coluna %d) não fecha com o TOTAL.', $tabela, $i + 1));
                }
            }
        };

        $conferir('quantitativo', [0, 1, 2, 3], self::numerosDoRodape($xpath, "//div[@id='divTotal']//tfoot/tr"));

        $temGenero = array_filter($saida, static fn (array $v): bool => $v[4] !== null) !== [];
        if ($temGenero) {
            $conferir('gênero', [4, 5], self::numerosDoRodape($xpath, "//div[@id='divTotalPorSexo']//tfoot/tr"));
        }

        $temFaixa = array_filter($saida, static fn (array $v): bool => $v[6] !== null) !== [];
        if ($temFaixa) {
            $totais = array_fill(0, 8, null);
            foreach ($xpath->query("//div[@id='divTotalFaixaEtaria']//tfoot/tr") ?: [] as $tr) {
                $tds = $xpath->query('./td', $tr);
                if ($tds === false || $tds->length < 3) {
                    continue;
                }
                $faixa = self::indiceDaFaixa((string) $tds->item(0)?->textContent);
                if ($faixa !== null) {
                    $totais[$faixa * 2] = self::numero((string) $tds->item(1)?->textContent);
                    $totais[$faixa * 2 + 1] = self::numero((string) $tds->item(2)?->textContent);
                }
            }
            $conferir('faixa etária', [6, 7, 8, 9, 10, 11, 12, 13], $totais);
        }
    }

    /**
     * @return list<int|null> números do rodapé (sem a célula do rótulo TOTAL)
     */
    private static function numerosDoRodape(\DOMXPath $xpath, string $caminho): array
    {
        $tr = $xpath->query($caminho)?->item(0);
        if ($tr === null) {
            return [];
        }

        $numeros = [];
        foreach ($xpath->query('./th', $tr) ?: [] as $celula) {
            $numeros[] = self::numero($celula->textContent);
        }

        return $numeros;
    }

    private static function xpath(string $html): \DOMXPath
    {
        $documento = new \DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $documento->loadHTML('<?xml encoding="UTF-8">' . $html, \LIBXML_NONET | \LIBXML_NOWARNING | \LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new \DOMXPath($documento);
    }

    /**
     * @return array{0: ?string, 1: list<?int>}
     */
    private static function linha(\DOMXPath $xpath, \DOMNode $tr): array
    {
        $th = $xpath->query('./th', $tr)?->item(0);
        $numeros = [];
        foreach ($xpath->query('./td', $tr) ?: [] as $td) {
            $numeros[] = self::numero($td->textContent);
        }

        return [$th !== null ? self::sigla($th->textContent) : null, $numeros];
    }

    private static function totalAdvogados(\DOMXPath $xpath): ?int
    {
        $celula = $xpath->query("//div[@id='divTotal']//tfoot/tr/th")?->item(0);

        return $celula !== null ? self::numero($celula->textContent) : null;
    }

    private static function sigla(string $texto): ?string
    {
        $sigla = strtoupper(trim($texto));

        return preg_match('/^[A-Z]{2}$/', $sigla) === 1 ? $sigla : null;
    }

    private static function numero(string $texto): ?int
    {
        $digitos = preg_replace('/[^\d]/', '', $texto);

        return $digitos === null || $digitos === '' ? null : (int) $digitos;
    }

    private static function indiceDaFaixa(string $rotulo): ?int
    {
        return match (true) {
            str_contains($rotulo, '25') => 0,
            str_contains($rotulo, '26') => 1,
            str_contains($rotulo, '41') => 2,
            str_contains($rotulo, '60') => 3,
            default => null,
        };
    }
}
