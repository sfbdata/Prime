<?php

declare(strict_types=1);

namespace App\Twig;

use App\Processo\Service\SituacaoProcessoRotulo;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ proc.situacaoProcesso|situacao_processo }}` — código gravado → rótulo de exibição
 * ("EM_ANDAMENTO" → "Em andamento"). O mapeamento mora em `SituacaoProcessoRotulo`, o mesmo que o
 * PDF do resumo usa; valor desconhecido volta como veio.
 */
final class SituacaoProcessoExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('situacao_processo', static fn (?string $situacao): string => SituacaoProcessoRotulo::de((string) $situacao)),
        ];
    }
}
