<?php

declare(strict_types=1);

namespace App\Twig;

use App\Kanban\Service\SanitizadorTextoKanban;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Exibe com segurança a descrição de card e os comentários do Kanban.
 *
 *     {{ card.descricao|texto_kanban }}
 *
 * Declarado `is_safe: html`, dispensa o `|raw` — que é o ponto: os dois lugares que exibiam este
 * conteúdo usavam `|raw` sobre HTML NÃO sanitizado, o XSS armazenado que este filtro fecha. Como
 * sanitiza na exibição, também limpa o conteúdo LEGADO gravado cru antes da correção.
 */
final class TextoKanbanExtension extends AbstractExtension
{
    public function __construct(
        private readonly SanitizadorTextoKanban $sanitizador,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('texto_kanban', $this->textoKanban(...), ['is_safe' => ['html']]),
        ];
    }

    private function textoKanban(?string $conteudo): string
    {
        return $this->sanitizador->paraExibicao($conteudo) ?? '';
    }
}
