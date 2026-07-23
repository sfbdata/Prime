<?php

declare(strict_types=1);

namespace App\Shared\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Limpa e prepara para exibição o texto escrito no editor rico (anotações, observações,
 * relatórios). É a única barreira entre o que o usuário digita e o que volta para a tela como
 * HTML — sem ela, a barra de formatação seria XSS armazenado: um usuário injeta script que roda
 * no navegador dos colegas do mesmo escritório.
 *
 * Duas portas, e as duas sanitizam:
 *  - `limpar()`   — ENTRADA: chamado pelo UseCase antes de persistir. O banco só guarda HTML já
 *                   limpo, então um vazamento futuro na exibição não vira execução.
 *  - `paraExibicao()` — SAÍDA: sanitiza de novo (defesa em profundidade — cobre o que entrou por
 *                   outro caminho, como importação ou escrita direta no banco) e resolve o legado.
 *
 * **Legado.** Antes do editor, estes campos guardavam TEXTO PURO, e 95 dos 149 registros
 * existentes são multilinha. Exibi-los como HTML colapsaria as quebras de linha num parágrafo só.
 * A distinção é medida, não heurística: em 2026-07-23, NENHUM dos 149 registros continha `<`
 * (`pasta_observacao_detalhes`, `pasta_observacao_financeira`, `pasta_mensagem`, `tarefa_mensagem`).
 * Conteúdo novo sempre vem do editor embrulhado em bloco (`<p>…</p>`), então a presença de `<`
 * separa os dois conjuntos sem ambiguidade — e o conjunto legado está congelado, porque daqui em
 * diante toda escrita passa por `limpar()`.
 *
 * A saída de ambos os métodos é SEMPRE segura para renderizar com `|raw`.
 */
final class SanitizadorTextoRico
{
    /**
     * Classes que o editor legitimamente produz (alinhamento, recuo, cor, fundo, tamanho, fonte).
     * O sanitizador libera o atributo `class`, mas não filtra o VALOR — sem esta trava daria para
     * injetar utilitário do Bootstrap (`d-none` esconde a observação, `position-fixed` sobrepõe a
     * tela). Como o atributo `style` não é liberado, a formatação visual sai toda por estas classes.
     */
    private const CLASSE_PERMITIDA = '/^ql-(align|indent|color|bg|size|font)-[a-z0-9#-]+$/i';

    public function __construct(
        // O nome do argumento tem de ser EXATAMENTE o nome do sanitizador no YAML (`textoRico`) —
        // é assim que o Symfony expõe o alias (`HtmlSanitizerInterface $textoRico`). Com qualquer
        // outro nome (ex.: `$textoRicoSanitizer`) o autowiring cai calado no sanitizador `default`,
        // que é bem mais permissivo (aceita tabela, link e imagem). Ver `debug:autowiring`.
        private readonly HtmlSanitizerInterface $textoRico,
    ) {
    }

    /**
     * ENTRADA: limpa o HTML vindo do editor antes de persistir.
     *
     * Texto sem `<` passa INTACTO, e isso é deliberado: sem `<` não há como formar marcação, então
     * não há o que sanitizar — e sanitizar mesmo assim codificaria `&` para `&amp;` no banco, que
     * a exibição (vendo conteúdo sem `<`, logo "legado") escaparia de novo, mostrando `&amp;` na
     * tela. Passar intacto preserva o round-trip e mantém a invariante do discriminador.
     */
    public function limpar(?string $html): ?string
    {
        if ($html === null || !str_contains($html, '<')) {
            return $html;
        }

        return $this->filtrarClasses($this->textoRico->sanitize($html));
    }

    /**
     * Comprimento do TEXTO visível, ignorando a marcação. O limite de caracteres precisa valer
     * sobre o que a pessoa escreveu — cobrar o HTML puniria quem formatou o mesmo texto.
     */
    public function comprimentoDoTexto(?string $html): int
    {
        if ($html === null) {
            return 0;
        }

        $texto = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return mb_strlen(trim($texto));
    }

    /**
     * SAÍDA: devolve HTML seguro para `|raw`, preservando o legado em texto puro.
     */
    public function paraExibicao(?string $conteudo): ?string
    {
        if ($conteudo === null) {
            return null;
        }

        // Legado (texto puro): escapa e restaura as quebras de linha — exibição idêntica ao
        // `|nl2br` que estas telas usavam antes do editor. Ver nota de medição no topo da classe.
        if (!str_contains($conteudo, '<')) {
            return nl2br(htmlspecialchars($conteudo, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), false);
        }

        return $this->limpar($conteudo);
    }

    /**
     * O editor entrega `<p><br></p>` quando nada foi digitado — não-vazio como string, vazio como
     * conteúdo. Sem esta checagem, a validação de "não pode ser vazio" passaria batido no servidor.
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

    /**
     * Descarta toda classe fora do conjunto do editor. Roda DEPOIS do sanitizador, então opera
     * sobre HTML já normalizado (atributos entre aspas duplas) — por isso a regex é confiável aqui.
     */
    private function filtrarClasses(string $html): string
    {
        return preg_replace_callback(
            '/\sclass="([^"]*)"/i',
            static function (array $captura): string {
                $mantidas = array_filter(
                    preg_split('/\s+/', trim($captura[1])) ?: [],
                    static fn (string $classe): bool => $classe !== ''
                        && preg_match(self::CLASSE_PERMITIDA, $classe) === 1,
                );

                return $mantidas === [] ? '' : ' class="' . implode(' ', $mantidas) . '"';
            },
            $html,
        ) ?? $html;
    }
}
