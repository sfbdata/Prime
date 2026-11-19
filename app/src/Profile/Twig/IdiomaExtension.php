<?php

declare(strict_types=1);

namespace App\Profile\Twig;

use App\Profile\Enum\FormatoDeData;
use App\Profile\Enum\FormatoDeNumero;
use App\Profile\Enum\FusoHorario;
use App\Profile\Enum\Idioma;
use App\Shared\Formato\FormatoDoIdiomaExtension;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `idioma_atual()` (o `lang` e o `dir` do `<html>`, o nome no Meu Perfil e o marcado no modal) e `idiomas()` (a
 * lista do modal, na ordem do desenho, só os idiomas oferecidos). Spec idioma-do-sistema, entrega 1.
 *
 * "Opções avançadas" (spec sistema-em-ingles, §2): `opcoes_avancadas()` devolve o fuso, o formato de data e o de
 * números em uso (lidos dos atributos que o `FormatoDaRequisicaoListener` pôs na requisição; nulo = automático, e
 * o fuso padrão é Brasília), e `fusos_horarios()`, `formatos_de_data()` e `formatos_de_numero()` as listas do modal.
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
            new TwigFunction('idiomas', static fn (): array => Idioma::oferecidos()),
            new TwigFunction('opcoes_avancadas', $this->opcoesAvancadas(...)),
            new TwigFunction('fusos_horarios', static fn (): array => FusoHorario::cases()),
            new TwigFunction('formatos_de_data', static fn (): array => FormatoDeData::cases()),
            new TwigFunction('formatos_de_numero', static fn (): array => FormatoDeNumero::cases()),
        ];
    }

    public function atual(): Idioma
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale();

        return Idioma::emUso($locale !== null ? str_replace('_', '-', $locale) : null);
    }

    /** @return array{fuso: FusoHorario, fusoEscolhido: bool, data: ?FormatoDeData, numero: ?FormatoDeNumero} */
    public function opcoesAvancadas(): array
    {
        $atributos = $this->requestStack->getMainRequest()?->attributes;
        $texto = static fn (string $nome): string => (string) $atributos?->get($nome, '');
        $fuso = FusoHorario::tryFrom($texto(FormatoDoIdiomaExtension::ATRIBUTO_FUSO));

        return [
            'fuso'          => $fuso ?? FusoHorario::PADRAO,
            'fusoEscolhido' => $fuso !== null,
            'data'          => FormatoDeData::tryFrom($texto(FormatoDoIdiomaExtension::ATRIBUTO_DATA)),
            'numero'        => FormatoDeNumero::tryFrom($texto(FormatoDoIdiomaExtension::ATRIBUTO_NUMERO)),
        ];
    }
}
