<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf\Diagramacao;

use App\AssinaturaEletronica\Pdf\Fonte;
use App\AssinaturaEletronica\Pdf\MedidorDeTexto;

/**
 * O texto de um bloco em palavras e linhas: o `tok` e o `quebra` das duas colunas (dc:400), usados também na página
 * inteira (sem {b} o resultado é o mesmo do `linhas` do desenho; com {b}, sai em negrito como na prévia da tela).
 */
final class TextoComNegrito
{
    private const MARCAS_DO_ASSISTENTE = ["\u{1}", "\u{2}"];

    private function __construct()
    {
    }

    /**
     * `tok(x)`: palavras com a marca de negrito. Um trecho colado ao anterior (sem espaço entre eles) emenda na
     * última palavra, que fica com o negrito dela.
     *
     * @return list<Palavra>
     */
    public static function palavras(string $texto): array
    {
        $saida = [];
        $negrito = false;
        $anterior = ' ';

        $partes = preg_split('/(\{b\}|\{\/b\})/', str_replace(self::MARCAS_DO_ASSISTENTE, '', $texto), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($partes as $parte) {
            if ($parte === '{b}' || $parte === '{/b}') {
                $negrito = $parte === '{b}';
                continue;
            }
            if ($parte === '') {
                continue;
            }

            $cola = preg_match('/^\s/u', $parte) !== 1 && preg_match('/\s$/u', $anterior) !== 1 && $saida !== [];
            foreach (preg_split('/\s+/u', $parte, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $palavra) {
                if ($i === 0 && $cola) {
                    $ultima = array_pop($saida);
                    $saida[] = new Palavra($ultima->texto . $palavra, $ultima->negrito);
                    continue;
                }
                $saida[] = new Palavra($palavra, $negrito);
            }
            $anterior = $parte;
        }

        return $saida;
    }

    /** O texto sem as marcas de negrito e do assistente (número, título e texto lateral). */
    public static function semMarcas(string $texto): string
    {
        return str_replace(['{b}', '{/b}', ...self::MARCAS_DO_ASSISTENTE], '', $texto);
    }

    /**
     * Quebra gulosa: a palavra vai para a linha de baixo quando não cabe; palavra maior que a caixa fica sozinha.
     *
     * @param list<Palavra> $palavras
     *
     * @return list<list<Palavra>>
     */
    public static function quebrar(array $palavras, float $tamanho, float $largura, float $espaco, bool $tudoNegrito): array
    {
        $linhas = [];
        $linha = [];
        $ocupado = 0.0;

        foreach ($palavras as $palavra) {
            $medida = self::largura($palavra, $tamanho, $tudoNegrito);
            if ($linha !== [] && $ocupado + $espaco + $medida > $largura) {
                $linhas[] = $linha;
                $linha = [$palavra];
                $ocupado = $medida;
                continue;
            }
            $ocupado += ($linha !== [] ? $espaco : 0.0) + $medida;
            $linha[] = $palavra;
        }

        if ($linha !== []) {
            $linhas[] = $linha;
        }

        return $linhas;
    }

    public static function largura(Palavra $palavra, float $tamanho, bool $tudoNegrito): float
    {
        return MedidorDeTexto::largura($palavra->texto, Fonte::arial($tudoNegrito || $palavra->negrito), $tamanho);
    }
}
