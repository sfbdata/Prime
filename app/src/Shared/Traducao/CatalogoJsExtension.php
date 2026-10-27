<?php

declare(strict_types=1);

namespace App\Shared\Traducao;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * O catálogo do JS (spec idioma-do-sistema, entrega 3): `{{ catalogo_js('menu.usuario.js') }}` escreve um
 * `<script type="application/json" data-bj-t>` com as chaves daqueles prefixos, no idioma da requisição (o que faltar
 * vem do português). Fica fora do script inline por causa da CSP; o `public/js/bj-traducao.js` lê e expõe
 * `window.BJ_T.t('chave', {x})`. O texto vai cru, com a troca simples de `{x}` no navegador: chave do JS não usa
 * plural nem select do ICU.
 */
final class CatalogoJsExtension extends AbstractExtension
{
    private const LOCALE_PADRAO = 'pt_BR';
    private const DOMINIO = 'messages';

    public function __construct(
        #[Autowire(service: 'translator')]
        private readonly TranslatorBagInterface $tradutor,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('catalogo_js', $this->catalogoJs(...), ['is_safe' => ['html']])];
    }

    public function catalogoJs(string ...$prefixos): string
    {
        $json = json_encode($this->mensagens($prefixos), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);

        return '<script type="application/json" data-bj-t>' . $json . '</script>';
    }

    /**
     * @param list<string> $prefixos
     *
     * @return array<string, string>
     */
    public function mensagens(array $prefixos): array
    {
        $todas = [];
        for ($catalogo = $this->tradutor->getCatalogue($this->locale()); $catalogo !== null; $catalogo = $catalogo->getFallbackCatalogue()) {
            $todas += self::doCatalogo($catalogo);
        }
        $escolhidas = array_filter($todas, static fn (string $chave): bool => self::comecaComAlgum($chave, $prefixos), ARRAY_FILTER_USE_KEY);
        ksort($escolhidas);

        return $escolhidas;
    }

    /** @return array<string, string> */
    private static function doCatalogo(MessageCatalogueInterface $catalogo): array
    {
        return array_map(static fn (mixed $texto): string => (string) $texto, $catalogo->all(self::DOMINIO));
    }

    /** @param list<string> $prefixos */
    private static function comecaComAlgum(string $chave, array $prefixos): bool
    {
        foreach ($prefixos as $prefixo) {
            if ($chave === $prefixo || str_starts_with($chave, $prefixo . '.')) {
                return true;
            }
        }

        return false;
    }

    private function locale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? self::LOCALE_PADRAO;
    }
}
