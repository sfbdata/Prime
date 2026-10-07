<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Pasta\Service\MencoesDoRegistro;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ item.detalhe|registro_texto }}` — o `texto_rico` do Registro da pasta, com as @menções
 * exibidas como destaque. Recebe o conteúdo CRU do banco (não o resultado de `texto_rico`): a
 * sanitização acontece lá dentro, antes da troca dos tokens — ver {@see MencoesDoRegistro::exibir()}.
 */
final class MencoesDoRegistroExtension extends AbstractExtension
{
    public function __construct(
        private readonly MencoesDoRegistro $mencoes,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('registro_texto', $this->mencoes->exibir(...), ['is_safe' => ['html']]),
        ];
    }
}
