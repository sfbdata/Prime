<?php

declare(strict_types=1);

namespace App\Comercial\Twig;

use App\Comercial\Service\RastreioDaLeitura;
use Twig\Extension\RuntimeExtensionInterface;

/** O lado preguiçoso de {@see RastreioDaLeituraExtension}: só nasce na primeira chamada da função. */
final class RastreioDaLeituraRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly RastreioDaLeitura $rastreio,
    ) {
    }

    public function disponivel(): bool
    {
        return $this->rastreio->disponivel();
    }
}
