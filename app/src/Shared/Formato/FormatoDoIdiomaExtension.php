<?php

declare(strict_types=1);

namespace App\Shared\Formato;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Extension\CoreExtension;
use Twig\TwigFilter;

/**
 * Filtros de formato pelo idioma da requisição (spec idioma-do-sistema, entrega 2), no lugar dos formatos fixos:
 * `|data` (era `|date('d/m/Y')`), `|data_hora` (`|date('d/m/Y H:i')`), `|hora` (`|date('H:i')`) e
 * `|numero(casas)` (`|number_format(casas, ',', '.')`). A data passa pelo mesmo conversor do `|date` (texto,
 * timestamp ou objeto; fuso do Twig). Nulo ou vazio vira texto vazio (o `|date` de nulo mostraria a data de
 * agora). Sem requisição (e-mail em comando), o padrão é o português.
 */
final class FormatoDoIdiomaExtension extends AbstractExtension
{
    private const LOCALE_PADRAO = 'pt_BR';

    public function __construct(
        private readonly FormatoDoIdioma $formato,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('data', fn (Environment $twig, mixed $momento): string => $this->comData($twig, $momento, $this->formato->data(...)), ['needs_environment' => true]),
            new TwigFilter('data_hora', fn (Environment $twig, mixed $momento): string => $this->comData($twig, $momento, $this->formato->dataHora(...)), ['needs_environment' => true]),
            new TwigFilter('hora', fn (Environment $twig, mixed $momento): string => $this->comData($twig, $momento, $this->formato->hora(...)), ['needs_environment' => true]),
            new TwigFilter('numero', fn (float|int|string|null $valor, int $casas = 0): string => $this->formato->numero((float) $valor, $this->locale(), $casas)),
        ];
    }

    /** @param callable(\DateTimeInterface, string): string $escrever */
    private function comData(Environment $twig, mixed $momento, callable $escrever): string
    {
        if ($momento === null || $momento === '') {
            return '';
        }

        return $escrever($twig->getExtension(CoreExtension::class)->convertDate($momento), $this->locale());
    }

    private function locale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? self::LOCALE_PADRAO;
    }
}
