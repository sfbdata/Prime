<?php

declare(strict_types=1);

namespace App\Voto\Twig;

use App\Voto\Service\LiberacaoDoVotoNoEscritorio;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Funções Twig do BlueJus Voto:
 *
 *  - `voto_liberado()`: o Master liberou o Voto para o escritório da sessão (o Master passa sempre). Fica no `{% if %}`
 *    do item do menu, junto de `can_access_module('voto')` e `recurso_visivel('voto')`.
 */
final class VotoExtension extends AbstractExtension
{
    public function __construct(
        private readonly LiberacaoDoVotoNoEscritorio $liberacao,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('voto_liberado', $this->liberacao->liberadoParaOUsuarioAtual(...)),
        ];
    }
}
