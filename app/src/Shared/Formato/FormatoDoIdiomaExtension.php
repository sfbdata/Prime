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
 *
 * "Opções avançadas" (spec docs/specs/sistema-em-ingles.md, §2): os atributos da requisição abaixo, postos pelo
 * listener do Profile, fixam o formato da data e dos números e o fuso. O fuso vale só para o registro de data e hora
 * (`|data_hora`, `|hora`): a data pura (`|data`) é o dia do prazo e da audiência, e converter a meia-noite de Brasília
 * para outro fuso mostraria o dia anterior.
 */
final class FormatoDoIdiomaExtension extends AbstractExtension
{
    public const ATRIBUTO_FUSO = '_bj_fuso';
    public const ATRIBUTO_DATA = '_bj_formato_data';
    public const ATRIBUTO_NUMERO = '_bj_formato_numero';

    private const LOCALE_PADRAO = 'pt_BR';

    public function __construct(
        private readonly FormatoDoIdioma $formato,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('data', fn (Environment $twig, mixed $momento): string => $this->comData($twig, $momento, false, fn (\DateTimeInterface $d): string => $this->formato->data($d, $this->locale(), $this->atributo(self::ATRIBUTO_DATA))), ['needs_environment' => true]),
            new TwigFilter('data_hora', fn (Environment $twig, mixed $momento): string => $this->comData($twig, $momento, true, fn (\DateTimeInterface $d): string => $this->formato->dataHora($d, $this->locale(), $this->atributo(self::ATRIBUTO_DATA))), ['needs_environment' => true]),
            new TwigFilter('hora', fn (Environment $twig, mixed $momento): string => $this->comData($twig, $momento, true, fn (\DateTimeInterface $d): string => $this->formato->hora($d, $this->locale())), ['needs_environment' => true]),
            new TwigFilter('numero', fn (float|int|string|null $valor, int $casas = 0): string => $this->formato->numero((float) $valor, $this->locale(), $casas, $this->atributo(self::ATRIBUTO_NUMERO))),
        ];
    }

    /** @param callable(\DateTimeInterface): string $escrever */
    private function comData(Environment $twig, mixed $momento, bool $noFusoDaPessoa, callable $escrever): string
    {
        if ($momento === null || $momento === '') {
            return '';
        }
        $fuso = $noFusoDaPessoa ? $this->atributo(self::ATRIBUTO_FUSO) : null;

        return $escrever($twig->getExtension(CoreExtension::class)->convertDate($momento, $fuso));
    }

    private function atributo(string $nome): ?string
    {
        $valor = $this->requestStack->getMainRequest()?->attributes->get($nome);

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    private function locale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? self::LOCALE_PADRAO;
    }
}
