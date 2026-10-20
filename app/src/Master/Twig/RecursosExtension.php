<?php

declare(strict_types=1);

namespace App\Master\Twig;

use App\Master\Service\CatalogoDeRecursos;
use App\Master\Service\RecursosDoSistema;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Funções Twig das telas e recursos (spec docs/specs/perfis-1.2-telas-e-recursos.md §4, R5 e R6):
 *
 *  - `recurso_visivel('chave')`: false só para quem não é Master com o item desligado. Envolve o
 *    item de menu, o botão ou o atalho num `{% if %}`.
 *  - `recurso_marca('chave')`: a classe da marcação vermelha (opaco, contorno tracejado) para o
 *    Master quando o item está desligado; texto vazio nos demais casos.
 *  - `recurso_faixa_master()`: título da tela desligada que o Master está vendo (rota atual mapeada
 *    no catálogo e desligada), para a faixa vermelha; null quando não há faixa.
 */
final class RecursosExtension extends AbstractExtension
{
    public function __construct(
        private readonly RecursosDoSistema $recursos,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('recurso_visivel', $this->recursos->visivel(...)),
            new TwigFunction('recurso_marca', $this->recursos->marca(...)),
            new TwigFunction('recurso_faixa_master', $this->faixaDoMaster(...)),
        ];
    }

    public function faixaDoMaster(): ?string
    {
        $rota = (string) $this->requestStack->getMainRequest()?->attributes->get('_route', '');
        $chave = CatalogoDeRecursos::chaveDaRota($rota);
        if ($chave === null || $this->recursos->estaAtivo($chave) || !$this->recursos->usuarioEhMaster()) {
            return null;
        }

        return CatalogoDeRecursos::titulo($chave);
    }
}
