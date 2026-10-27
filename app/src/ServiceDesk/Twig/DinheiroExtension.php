<?php

declare(strict_types=1);

namespace App\ServiceDesk\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** `{{ 2000|reais }}` => "R$ 20,00". O Service Desk guarda dinheiro em centavos inteiros e formata só na borda. */
final class DinheiroExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('reais', [$this, 'reais'])];
    }

    public function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
