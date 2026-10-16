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
 * Posição que a página não trouxe fica nula.
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

        $comQuantitativo = array_filter($saida, static fn (array $v): bool => $v[0] !== null);
        if (count($comQuantitativo) < 27) {
            throw new FonteIndisponivel(sprintf('Quadro da Advocacia com %d seccionais no quantitativo (esperado 27).', count($comQuantitativo)));
        }

        ksort($saida);

        return ['uf' => $saida, 'totalAdvogados' => self::totalAdvogados($xpath)];
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
