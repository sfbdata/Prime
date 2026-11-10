<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * O sorteio do fundo de cada página (dc:409): xorshift de 32 bits com semente tirada do hash do documento.
 *
 *     sem = (parseInt(hs.slice(0, 8), 16) || 7) + pn * 1117
 *     rnd(): sem ^= sem << 13; sem ^= sem >>> 17; sem ^= sem << 5; return (sem >>> 0) / 4294967296
 *
 * O `<<` do JS trunca em 32 bits com sinal, mas o resultado final é `>>> 0`: basta manter o valor em 32 bits sem
 * sinal depois de cada passo. Mesma sequência do desenho, conferida pelo teste de paridade.
 */
final class Sorteio
{
    private const MASCARA = 0xFFFFFFFF;

    private function __construct(private int $semente)
    {
    }

    public static function daPagina(string $hash, int $pagina): self
    {
        $inicio = substr($hash, 0, 8);
        // parseInt(…, 16) lê só o prefixo hexadecimal; vazio ou zero vira 7.
        $hexadecimal = preg_match('/^[0-9a-f]*/i', $inicio, $m) === 1 ? $m[0] : '';
        $valor = $hexadecimal === '' ? 0 : (int) hexdec($hexadecimal);

        return new self((($valor === 0 ? 7 : $valor) + $pagina * 1117) & self::MASCARA);
    }

    /** Um número em [0, 1), como o `rnd()` do desenho. */
    public function proximo(): float
    {
        $s = $this->semente;
        $s ^= ($s << 13) & self::MASCARA;
        $s ^= $s >> 17;
        $s ^= ($s << 5) & self::MASCARA;
        $this->semente = $s & self::MASCARA;

        return $this->semente / 4294967296;
    }
}
