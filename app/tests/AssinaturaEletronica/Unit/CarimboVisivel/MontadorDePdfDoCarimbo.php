<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\CarimboVisivel;

use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;

/**
 * PDFs dos testes do carimbo que a `OficinaDePdfAssinado` não monta: objetos à escolha com tabela `xref` clássica, com
 * fluxo /XRef (e objetos dentro de /ObjStm comprimido) e o PDF assinado de ponta a ponta com fluxo /XRef. Também lê o
 * índice do acréscimo do carimbo, para conferir que cada posição aponta para o cabeçalho do objeto.
 */
final class MontadorDePdfDoCarimbo
{
    private const DICIONARIO_DA_ASSINATURA = "<</Type/Sig/Filter/Adobe.PPKLite/SubFilter/ETSI.CAdES.detached/M(D:20261109120000-03'00')/Name(Teste)"
        . '/ByteRange [0 ********** ********** **********]/Contents <%s>>>';

    private function __construct()
    {
    }

    /**
     * Objetos (número => corpo) com tabela `xref` clássica de 0 até o maior número; os que faltam ficam livres.
     *
     * @param array<int, string> $objetos
     */
    public static function classico(array $objetos, string $trailerExtra = ''): string
    {
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $posicoes = [];
        foreach ($objetos as $numero => $corpo) {
            $posicoes[$numero] = \strlen($pdf);
            $pdf .= $numero . " 0 obj\n" . $corpo . "\nendobj\n";
        }
        $tamanho = max(array_keys($objetos)) + 1;
        $xref = \strlen($pdf);
        $pdf .= "xref\n0 " . $tamanho . "\n0000000000 65535 f \n";
        for ($numero = 1; $numero < $tamanho; ++$numero) {
            $pdf .= isset($posicoes[$numero]) ? \sprintf('%010d 00000 n ', $posicoes[$numero]) . "\n" : "0000000000 00000 f \n";
        }

        return $pdf . "trailer\n<</Size " . $tamanho . '/Root 1 0 R' . $trailerExtra . ">>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    /**
     * Objetos soltos e, se houver, objetos num /ObjStm comprimido (o número dado ou o seguinte ao maior), com fluxo
     * /XRef sem /Index no fim: o fluxo é o número seguinte ao maior solto e o /Size cobre também os comprimidos.
     *
     * @param array<int, string> $soltos
     * @param array<int, string> $comprimidos
     */
    public static function comFluxoDeXref(array $soltos, array $comprimidos = [], ?int $numeroDoFluxo = null): string
    {
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $entradas = [0 => [0, 0, 65535]];
        foreach ($soltos as $numero => $corpo) {
            $entradas[$numero] = [1, \strlen($pdf), 0];
            $pdf .= $numero . " 0 obj\n" . $corpo . "\nendobj\n";
        }
        if ($comprimidos !== []) {
            $fluxo = $numeroDoFluxo ?? max(array_keys($soltos + $comprimidos)) + 1;
            [$cabecalho, $dados, $ordem] = ['', '', 0];
            foreach ($comprimidos as $numero => $corpo) {
                $cabecalho .= $numero . ' ' . \strlen($dados) . ' ';
                $dados .= $corpo . "\n";
                $entradas[$numero] = [2, $fluxo, $ordem++];
            }
            $comprimido = self::comprimir($cabecalho . $dados);
            $entradas[$fluxo] = [1, \strlen($pdf), 0];
            $pdf .= $fluxo . " 0 obj\n<</Type/ObjStm/N " . \count($comprimidos) . '/First ' . \strlen($cabecalho)
                . '/Filter/FlateDecode/Length ' . \strlen($comprimido) . ">>\nstream\n" . $comprimido . "\nendstream\nendobj\n";
        }

        return self::fecharComFluxo($pdf, $entradas);
    }

    /**
     * O PDF de uma página do `OficinaDePdfAssinado::pdfParaAssinar`, mas com fluxo /XRef e a página (3) num /ObjStm,
     * assinado de ponta a ponta (a faixa cobre o arquivo inteiro). /Size 8: o /ObjStm é o 6 e o /XRef o 7.
     */
    public static function assinadoComFluxoDeXref(string $extraNoDicionario = ''): string
    {
        $pdf = self::comFluxoDeXref([
            1 => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[4 0 R]/SigFlags 3>>>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            4 => '<</Type/Annot/Subtype/Widget/FT/Sig/T(Assinatura1)/V 5 0 R/Rect[0 0 0 0]/F 132/P 3 0 R>>',
            5 => str_replace('/ByteRange', $extraNoDicionario . '/ByteRange', \sprintf(self::DICIONARIO_DA_ASSINATURA, str_repeat('0', 2 * OficinaDePdfAssinado::RESERVA))),
        ], [
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Annots[4 0 R]>>',
        ]);

        return OficinaDePdfAssinado::assinar($pdf, OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave()));
    }

    /**
     * As entradas do índice do acréscimo (tabela clássica ou fluxo /XRef sem compressão): número => [geração, posição].
     *
     * @return array<int, array{int, int}>
     */
    public static function indiceDoAcrescimo(string $acrescimo): array
    {
        if (preg_match('/\/Type\/XRef\/Size \d+\/W\[1 4 2\]\/Index\[([\d ]+)\].*?\/Length (\d+)>>\nstream\n/s', $acrescimo, $p, \PREG_OFFSET_CAPTURE) === 1) {
            $grupos = array_map('intval', explode(' ', $p[1][0]));
            $dados = substr($acrescimo, $p[0][1] + \strlen($p[0][0]), (int) $p[2][0]);
            $entradas = [];
            for ($i = 0; $i < \count($grupos); $i += 2) {
                $entrada = (array) unpack('Ctipo/Nposicao/ngeracao', substr($dados, 7 * \intdiv($i, 2), 7));
                $entradas[$grupos[$i]] = [(int) $entrada['geracao'], (int) $entrada['posicao']];
            }

            return $entradas;
        }
        preg_match_all('/^(\d+) (\d+)\n((?:\d{10} \d{5} n \n)+)/m', substr($acrescimo, (int) strrpos($acrescimo, "\nxref\n")), $grupos, \PREG_SET_ORDER);
        $entradas = [];
        foreach ($grupos as [, $primeiro, , $linhas]) {
            foreach (str_split($linhas, 20) as $k => $linha) {
                $entradas[(int) $primeiro + $k] = [(int) substr($linha, 11, 5), (int) substr($linha, 0, 10)];
            }
        }

        return $entradas;
    }

    /**
     * O corpo de um objeto /ObjStm comprimido com os objetos (número => corpo), para pôr como objeto solto do
     * `classico`. O enchimento (espaços depois dos objetos) faz o fluxo inflado ficar grande e o comprimido pequeno.
     *
     * @param array<int, string> $objetos
     */
    public static function fluxoDeObjetos(array $objetos, int $enchimento = 0): string
    {
        [$cabecalho, $dados] = ['', ''];
        foreach ($objetos as $numero => $corpo) {
            $cabecalho .= $numero . ' ' . \strlen($dados) . ' ';
            $dados .= $corpo . "\n";
        }
        $comprimido = self::comprimir($cabecalho . $dados . str_repeat(' ', $enchimento));

        return '<</Type/ObjStm/N ' . \count($objetos) . '/First ' . \strlen($cabecalho) . '/Filter/FlateDecode/Length '
            . \strlen($comprimido) . ">>\nstream\n" . $comprimido . "\nendstream";
    }

    /**
     * Entradas `/nome<>` de 7 bytes com nomes diferentes (quatro algarismos na base 36), para encher um dicionário: com
     * nomes iguais, o PHP guardaria uma entrada só.
     */
    public static function entradasDistintas(int $quantas): string
    {
        $texto = '';
        for ($i = 0; $i < $quantas; ++$i) {
            $texto .= '/' . str_pad(base_convert((string) $i, 10, 36), 4, '0', \STR_PAD_LEFT) . '<>';
        }

        return $texto;
    }

    /**
     * zlib do conteúdo. O leitor do motor (como o protótipo) tira CR e LF do fim dos dados antes de inflar: se o
     * comprimido terminar num deles, um espaço a mais no fim do conteúdo muda a soma de conferência.
     */
    private static function comprimir(string $conteudo): string
    {
        $comprimido = (string) gzcompress($conteudo);
        while (str_ends_with($comprimido, "\r") || str_ends_with($comprimido, "\n")) {
            $conteudo .= ' ';
            $comprimido = (string) gzcompress($conteudo);
        }

        return $comprimido;
    }

    /** @param array<int, array{int, int, int}> $entradas tipo, campo 2 e campo 3 de cada número */
    private static function fecharComFluxo(string $pdf, array $entradas): string
    {
        $numero = max(array_keys(array_filter($entradas, static fn (array $entrada): bool => $entrada[0] !== 2))) + 1;
        $posicao = \strlen($pdf);
        $entradas[$numero] = [1, $posicao, 0];
        $tamanho = max(array_keys($entradas)) + 1;
        $dados = '';
        for ($n = 0; $n < $tamanho; ++$n) {
            [$tipo, $campo2, $campo3] = $entradas[$n] ?? [0, 0, 0];
            $dados .= \chr($tipo) . pack('N', $campo2) . pack('n', $campo3);
        }

        return $pdf . $numero . " 0 obj\n<</Type/XRef/Size " . $tamanho . '/W[1 4 2]/Root 1 0 R/Length ' . \strlen($dados)
            . ">>\nstream\n" . $dados . "\nendstream\nendobj\nstartxref\n" . $posicao . "\n%%EOF\n";
    }
}
