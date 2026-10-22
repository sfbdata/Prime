<?php

declare(strict_types=1);

namespace App\Tarefa\Twig;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Service\QuemCuidaDaMeta;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Ponte fina entre os templates e o {@see QuemCuidaDaMeta}: o cartão da lista não repete a regra de quem cuida da
 * meta (responsável ou criador), só pergunta ao mesmo serviço que a passagem de etapa usa.
 */
final class QuemCuidaDaMetaExtension extends AbstractExtension
{
    public function __construct(
        private readonly QuemCuidaDaMeta $quemCuida,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cuida_da_meta', $this->cuida(...)),
        ];
    }

    public function cuida(Tarefa $meta, ?object $pessoa): bool
    {
        return $pessoa instanceof User && $this->quemCuida->ehResponsavelOuCriador($meta, $pessoa);
    }
}
