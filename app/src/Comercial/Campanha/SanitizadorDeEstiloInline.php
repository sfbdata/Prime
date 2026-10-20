<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * O `symfony/html-sanitizer` não analisa CSS: este sanitizador do atributo `style` tira as declarações que buscam algo
 * de fora ou rodam código (`url(`, `image-set(`, `image(`, `cross-fade(`, `element(`, `expression(`, `@import`, `javascript:`, `behavior`, `-moz-binding`) e as que
 * usam escape de CSS (`\`), que serviriam para disfarçar as anteriores (spec `comercial-e-emails.md` §8.3). O resto do
 * estilo inline fica, porque é assim que e-mail se formata.
 */
final class SanitizadorDeEstiloInline implements AttributeSanitizerInterface
{
    private const PROIBIDO = '/url\(|image-set\(|image\(|cross-fade\(|element\(|expression\(|@import|javascript:|behavior|-moz-binding|\\\\/i';

    public function getSupportedElements(): ?array
    {
        return null;
    }

    /** @return list<string> */
    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $limpo = self::limpar($value);

        return $limpo === '' ? null : $limpo;
    }

    /** Declaração por declaração; comentário de CSS sai antes (`u/ * * /rl(` não escapa da regra). */
    public static function limpar(string $estilo): string
    {
        $semComentario = (string) preg_replace('{/\*.*?\*/}s', '', $estilo);
        $mantidas = [];
        foreach (explode(';', $semComentario) as $declaracao) {
            $declaracao = trim($declaracao);
            $compacta = (string) preg_replace('/\s+/', '', $declaracao);
            if ($declaracao === '' || !str_contains($declaracao, ':') || preg_match(self::PROIBIDO, $compacta) === 1) {
                continue;
            }
            $mantidas[] = $declaracao;
        }

        return implode(';', $mantidas);
    }
}
