<?php

declare(strict_types=1);

namespace App\Kanban\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Limpa e prepara para exibição a descrição de card e os comentários do Kanban.
 *
 * Contexto: até 2026-07-23 o Kanban gravava o HTML do editor Quill CRU e o exibia com `|raw` — XSS
 * armazenado. Um usuário punha `<script>` num card e ele rodava no navegador de todo colega que
 * abrisse o card. Este serviço fecha a brecha nas duas pontas:
 *  - `limpar()`       — ENTRADA: chamado pelos UseCases antes de persistir.
 *  - `paraExibicao()` — SAÍDA: sanitiza de novo, o que também limpa o conteúdo LEGADO já gravado
 *                       cru antes desta correção (defesa em profundidade e remediação ao mesmo tempo).
 *
 * Difere do `SanitizadorTextoRico` (anotações) de propósito: a barra do Kanban é anterior e mais
 * ampla — oferece LINK e bloco de CÓDIGO. Por isso um sanitizador próprio (`kanban`), senão
 * sanitizar com o das anotações apagaria links e código dos cards existentes.
 *
 * Ao contrário das anotações, o Kanban SEMPRE guardou HTML (saída do Quill), então não há legado em
 * texto puro a discriminar — todo conteúdo passa direto pelo sanitizador. A saída é sempre segura
 * para `|raw`.
 */
final class SanitizadorTextoKanban
{
    public function __construct(
        // O nome do argumento tem de ser EXATAMENTE o nome do sanitizador no YAML (`kanban`) — é
        // assim que o Symfony expõe o alias. Com outro nome o autowiring cai calado no `default`.
        private readonly HtmlSanitizerInterface $kanban,
    ) {
    }

    public function limpar(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        return $this->kanban->sanitize($html);
    }

    public function paraExibicao(?string $html): ?string
    {
        return $this->limpar($html);
    }

    /**
     * O Quill entrega `<p><br></p>` quando nada foi digitado — não-vazio como string, vazio como
     * conteúdo. Sem esta checagem, um comentário "em branco" seria aceito.
     */
    public function estaVazio(?string $html): bool
    {
        if ($html === null) {
            return true;
        }

        $texto = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // \s do PCRE não cobre o espaço rígido (U+00A0) que o editor insere; /u + \p{Z} cobre.
        return preg_replace('/[\s\p{Z}]+/u', '', $texto) === '';
    }
}
