<?php

declare(strict_types=1);

namespace App\Master\Twig;

use App\Master\Service\AvisoDeManutencao;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `aviso_de_manutencao()`: o texto do aviso de manutenção que vale agora, ou null (layout/_globais/aviso_manutencao). */
final class AvisoDeManutencaoExtension extends AbstractExtension
{
    public function __construct(private readonly AvisoDeManutencao $aviso)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('aviso_de_manutencao', $this->aviso->texto(...))];
    }
}
