<?php

declare(strict_types=1);

namespace App\Twig;

use App\Shared\Service\SanitizadorTextoRico;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Exibe com segurança o texto escrito no editor rico (anotações, observações, relatórios).
 *
 *     {{ obs.conteudo|texto_rico }}
 *
 * O filtro sanitiza e é declarado `is_safe: html`, então dispensa o `|raw` — que é o ponto:
 * sem ele, cada tela precisaria lembrar de escrever `|raw`, e um `|raw` esquecido mostra a
 * marcação crua na tela enquanto um `|raw` sobre conteúdo NÃO sanitizado vira XSS. Aqui as duas
 * pontas ficam num lugar só.
 *
 * Conteúdo legado em texto puro (anterior ao editor) sai escapado e com as quebras de linha
 * preservadas — ver `SanitizadorTextoRico`.
 */
final class TextoRicoExtension extends AbstractExtension
{
    public function __construct(
        private readonly SanitizadorTextoRico $sanitizador,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('texto_rico', $this->textoRico(...), ['is_safe' => ['html']]),
        ];
    }

    private function textoRico(?string $conteudo): string
    {
        return $this->sanitizador->paraExibicao($conteudo) ?? '';
    }
}
