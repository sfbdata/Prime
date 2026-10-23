<?php

declare(strict_types=1);

namespace App\Expediente\Twig;

use App\Expediente\Service\CoresDoMarcador;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** `cor|cor_marcador`: a cor com que o marcador aparece na Lista do Expediente (ver CoresDoMarcador::paraTela). */
final class CorDoMarcadorExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('cor_marcador', CoresDoMarcador::paraTela(...)),
        ];
    }
}
