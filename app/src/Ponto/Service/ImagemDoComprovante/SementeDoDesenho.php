<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDoComprovante;

/**
 * O sorteio dos padrões de segurança (desenho 16 L553): xorshift de 32 bits com a semente tirada do selo do próprio
 * comprovante. O mesmo comprovante desenha sempre os mesmos padrões; outro selo, outros padrões.
 */
final class SementeDoDesenho
{
    private int $estado;

    public function __construct(string $seloHexadecimal)
    {
        $semente = (int) hexdec(substr($seloHexadecimal, 0, 8) ?: '0');
        $this->estado = $semente === 0 ? 7 : $semente & 0xFFFFFFFF;
    }

    /** Próximo número entre 0 (inclusive) e 1 (exclusive). */
    public function proximo(): float
    {
        $s = $this->estado;
        $s ^= ($s << 13) & 0xFFFFFFFF;
        $s ^= $s >> 17;
        $s ^= ($s << 5) & 0xFFFFFFFF;
        $this->estado = $s & 0xFFFFFFFF;

        return $this->estado / 4294967296;
    }
}
