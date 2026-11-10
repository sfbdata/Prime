<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Porta do `infla` do protótipo (FlateDecode, formato zlib) com teto de saída: a entrada vai em pedaços de 1 KB, e o
 * inflate para assim que a saída passa do limite. Com a taxa máxima do deflate (cerca de 1.000 para 1), cada pedaço
 * rende no máximo 1 MB, então a memória fica perto do limite mesmo com bomba de compressão.
 */
final class InfladorLimitado
{
    private const PEDACO = 1024;

    private function __construct()
    {
    }

    /** Null se o dado não for zlib válido e completo, ou se a saída passar de `$limiteEmBytes`. */
    public static function inflar(string $comprimido, int $limiteEmBytes): ?string
    {
        return SemAvisos::executar(static function () use ($comprimido, $limiteEmBytes): ?string {
            $contexto = inflate_init(\ZLIB_ENCODING_DEFLATE);
            if ($contexto === false) {
                return null;
            }

            $saida = '';
            $tamanho = \strlen($comprimido);
            for ($posicao = 0; $posicao < $tamanho; $posicao += self::PEDACO) {
                $parte = inflate_add($contexto, substr($comprimido, $posicao, self::PEDACO), \ZLIB_SYNC_FLUSH);
                if ($parte === false) {
                    return null;
                }
                $saida .= $parte;
                if (\strlen($saida) > $limiteEmBytes) {
                    return null;
                }
                if (inflate_get_status($contexto) === \ZLIB_STREAM_END) {
                    return $saida;
                }
            }

            return null;
        });
    }
}
