<?php

declare(strict_types=1);

namespace App\Profile\Twig;

use App\Profile\Enum\Idioma;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `idioma_atual()` (o `lang` e o `dir` do `<html>`, o nome no Meu Perfil e o marcado no modal) e `idiomas()` (a
 * lista do modal, na ordem do desenho). Spec idioma-do-sistema, entrega 1.
 */
final class IdiomaExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('idioma_atual', $this->atual(...)),
            new TwigFunction('idiomas', static fn (): array => Idioma::cases()),
        ];
    }

    public function atual(): Idioma
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale();

        return Idioma::emUso($locale !== null ? str_replace('_', '-', $locale) : null);
    }
}
