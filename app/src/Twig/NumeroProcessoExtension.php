<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Número de processo cru → máscara CNJ para exibição.
 *
 * O banco guarda os 20 dígitos sem pontuação ("10593167220224013400") e a tela
 * imprimia exatamente isso — 20 algarismos colados, que ninguém confere de
 * olho e ninguém dita ao telefone. O padrão CNJ (Resolução 65/2008) é
 * NNNNNNN-DD.AAAA.J.TR.OOOO.
 *
 * Mesma filosofia do `documento_br`: a regra é a CONTAGEM DE DÍGITOS, e o que
 * não bate 20 volta EXATAMENTE como veio. Número fora do padrão existe (base
 * antiga, processo físico), e mascarar o que não bate exigiria inventar ou
 * descartar dígito — número errado com máscara bonita deixa de parecer errado.
 *
 * Isto é APRESENTAÇÃO: não valida o dígito verificador e não normaliza para
 * gravação.
 */
final class NumeroProcessoExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('numero_cnj', $this->numeroCnj(...)),
        ];
    }

    public function numeroCnj(?string $numero): string
    {
        $original = (string) $numero;
        $digitos  = preg_replace('/\D/', '', $original) ?? '';

        if (\strlen($digitos) !== 20) {
            return $original;
        }

        return substr($digitos, 0, 7) . '-' . substr($digitos, 7, 2) . '.'
             . substr($digitos, 9, 4) . '.' . substr($digitos, 13, 1) . '.'
             . substr($digitos, 14, 2) . '.' . substr($digitos, 16, 4);
    }
}
