<?php

declare(strict_types=1);

namespace App\Voto\Service;

/**
 * O corpo HTML da minuta (vocabulário de _minuta_ata_corpo.html.twig) em WordprocessingML, o `<w:body>` do .docx.
 * Mesmas medidas do desenho (bj-voto-ata.js): Arial 11 pt, justificado, título centralizado em 13 pt, tabela com borda
 * cinza, `<mark>` como destaque amarelo e linha de assinatura centralizada.
 */
final class ConversorDaMinutaParaDocx
{
    public function converter(string $html): string
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML('<minuta>' . $html . '</minuta>', \LIBXML_NONET) || null === $dom->documentElement) {
            throw new \InvalidArgumentException('O corpo da minuta não é um HTML bem formado.');
        }

        $xml = '';
        foreach ($dom->documentElement->childNodes as $bloco) {
            if ($bloco instanceof \DOMElement) {
                $xml .= $this->bloco($bloco);
            }
        }

        return $xml;
    }

    private function bloco(\DOMElement $e): string
    {
        $classes = ' ' . $e->getAttribute('class') . ' ';

        return match ($e->tagName) {
            'h1' => $this->paragrafoDe($e, ['b' => true, 'c' => true, 'tam' => 26, 'apos' => 240]),
            'h2' => $this->paragrafoDe($e, ['b' => true, 'apos' => 100]),
            'table' => $this->tabela($e),
            'div' => $this->assinaturas($e),
            default => $this->paragrafoDe($e, [
                'b' => str_contains($classes, ' negrito '),
                'tam' => str_contains($classes, ' pequeno ') ? 18 : 22,
                'apos' => str_contains($classes, ' negrito ') ? 60 : 140,
            ]),
        };
    }

    /**
     * @param list<array{0: string, 1: bool}>                $segmentos texto e destaque, na ordem
     * @param array{b?: bool, c?: bool, tam?: int, apos?: int} $o
     */
    private function paragrafo(array $segmentos, array $o = []): string
    {
        $alinhamento = ($o['c'] ?? false) ? 'center' : 'both';
        $runs = '';
        foreach ($segmentos as [$texto, $destaque]) {
            $runs .= $this->run($texto, $o['b'] ?? false, $o['tam'] ?? 22, $destaque);
        }

        return '<w:p><w:pPr><w:spacing w:after="' . ($o['apos'] ?? 140) . '" w:line="300" w:lineRule="auto"/><w:jc w:val="' . $alinhamento . '"/></w:pPr>' . $runs . '</w:p>';
    }

    /** @param array{b?: bool, c?: bool, tam?: int, apos?: int} $o */
    private function paragrafoDe(\DOMElement $e, array $o = []): string
    {
        return $this->paragrafo(self::aparar(self::segmentos($e)), $o);
    }

    /**
     * O texto do elemento em segmentos (texto, destaque): espaço em branco colapsado, sem espaço nas pontas do parágrafo.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function segmentos(\DOMNode $no, bool $destaque = false): array
    {
        if ($no instanceof \DOMText) {
            return [[(string) preg_replace('/\s+/u', ' ', $no->wholeText), $destaque]];
        }
        $lista = [];
        foreach ($no->childNodes as $filho) {
            $lista = array_merge($lista, self::segmentos($filho, $destaque || ($no instanceof \DOMElement && 'mark' === $no->tagName)));
        }

        return $lista;
    }

    /**
     * @param list<array{0: string, 1: bool}> $lista
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function aparar(array $lista): array
    {
        if ([] === $lista) {
            return [];
        }
        $lista[0][0] = ltrim($lista[0][0]);
        $ultimo = \count($lista) - 1;
        $lista[$ultimo][0] = rtrim($lista[$ultimo][0]);

        return array_values(array_filter($lista, static fn (array $s): bool => '' !== $s[0]));
    }

    private function run(string $texto, bool $negrito, int $tam, bool $destaque): string
    {
        return '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>' . ($negrito ? '<w:b/>' : '')
            . ($destaque ? '<w:highlight w:val="yellow"/>' : '') . '<w:sz w:val="' . $tam . '"/></w:rPr><w:t xml:space="preserve">'
            . htmlspecialchars($texto, \ENT_XML1 | \ENT_QUOTES, 'UTF-8') . '</w:t></w:r>';
    }

    private function tabela(\DOMElement $tabela): string
    {
        $linhas = $tabela->getElementsByTagName('tr');
        $colunas = max(1, null === $linhas->item(0) ? 1 : \count(self::celulas($linhas->item(0))));
        $largura = intdiv(9000, $colunas);
        $bordas = '';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $lado) {
            $bordas .= '<w:' . $lado . ' w:val="single" w:sz="4" w:color="999999"/>';
        }
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders>' . $bordas . '</w:tblBorders></w:tblPr><w:tblGrid>'
            . str_repeat('<w:gridCol w:w="' . $largura . '"/>', $colunas) . '</w:tblGrid>';
        foreach ($linhas as $linha) {
            $xml .= '<w:tr>';
            foreach (self::celulas($linha) as $celula) {
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $largura . '" w:type="dxa"/></w:tcPr>'
                    . $this->paragrafoDe($celula, ['b' => 'th' === $celula->tagName, 'tam' => 20, 'apos' => 40]) . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml . '</w:tbl>' . $this->paragrafo([], ['apos' => 60]);
    }

    /** @return list<\DOMElement> */
    private static function celulas(\DOMNode $linha): array
    {
        $celulas = [];
        foreach ($linha->childNodes as $no) {
            if ($no instanceof \DOMElement) {
                $celulas[] = $no;
            }
        }

        return $celulas;
    }

    private function assinaturas(\DOMElement $div): string
    {
        $xml = $this->paragrafo([], ['apos' => 600]);
        foreach ($div->getElementsByTagName('p') as $nome) {
            $xml .= $this->paragrafo([['_______________________________________', false]], ['c' => true, 'apos' => 0])
                . $this->paragrafoDe($nome, ['c' => true, 'apos' => 360]);
        }

        return $xml;
    }
}
