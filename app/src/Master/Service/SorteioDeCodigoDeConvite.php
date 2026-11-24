<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * O código de convite de um convênio (spec docs/specs/convenios.md, §5.3): 8 letras e números, sem 0, O, 1 e I (que se
 * confundem ao ditar ou copiar). Sorteio com `random_int` (gerador criptográfico), para não ser adivinhável.
 */
final class SorteioDeCodigoDeConvite
{
    public const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const TAMANHO = 8;

    public function sortear(): string
    {
        $codigo = '';
        for ($i = 0; $i < self::TAMANHO; ++$i) {
            $codigo .= self::ALFABETO[random_int(0, \strlen(self::ALFABETO) - 1)];
        }

        return $codigo;
    }
}
