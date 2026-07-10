<?php

declare(strict_types=1);

namespace App\Cobranca\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Apresentação de dinheiro da Gestão de Cobranças. O domínio guarda dinheiro em CENTAVOS
 * inteiros (invariável de precisão financeira das Etapas 2–3); esta é a ÚNICA camada que
 * converte para exibição — os Output DTOs carregam o int cru e o Twig formata com `|centavos`.
 * Nunca fazer aritmética de dinheiro no template; só formatar.
 */
final class CobrancaExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('centavos', $this->centavos(...)),
        ];
    }

    /** Centavos inteiros → "R$ 1.234,56". */
    public function centavos(?int $centavos): string
    {
        return 'R$ ' . number_format(($centavos ?? 0) / 100, 2, ',', '.');
    }
}
