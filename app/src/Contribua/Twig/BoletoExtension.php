<?php

declare(strict_types=1);

namespace App\Contribua\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `contribua_boleto()` do parcial `contribua/_boleto.html.twig`; o trabalho fica no BoletoRuntime (carregado só quando a página usa). */
final class BoletoExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('contribua_boleto', [BoletoRuntime::class, 'boleto'])];
    }
}
