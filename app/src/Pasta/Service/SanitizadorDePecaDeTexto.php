<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Comercial\Campanha\SanitizadorDeEstiloInline;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Limpa o HTML de uma peça escrita no editor (TinyMCE) antes de ir para o arquivo (auditoria 5.06).
 *
 * O editor é do navegador e não merece confiança: um POST feito à mão grava o que quiser. O servidor
 * deixa só o que o editor produz hoje. Parte da configuração do {@see SanitizadorDeModeloDePeca} e
 * acrescenta o que a peça tem e o modelo não:
 *  - `img` só com `src` de imagem enviada pelo editor DESTE escritório, conferido pela mesma allowlist
 *    do export ({@see ReferenciasDePecaHtml::nomeDeImagemDoEscritorio()}): fora `http(s)://`,
 *    `data:`, travessia e a subpasta de outro escritório;
 *  - `a` com `href` só `http`, `https` ou `mailto` (o texto do link fica, o `href` perigoso sai);
 *  - tabela do TinyMCE inteira (`colgroup`, `col`, `caption`, `tfoot`, `border`, largura);
 *  - `h5`, `h6`, `pre` e `code` do menu de estilos;
 *  - a quebra de página do plugin `pagebreak`, que é um comentário (`<!-- pagebreak -->`) e o
 *    sanitizador jogaria fora com todos os outros comentários.
 *
 * `style` passa pelo {@see SanitizadorDeEstiloInline}; nenhum atributo `on*` é aceito, porque só os
 * atributos listados aqui passam. Elemento desconhecido é desembrulhado (o texto fica); os que
 * carregam código ou formulário saem com o conteúdo.
 *
 * Sem limite de tamanho: o padrão da biblioteca CORTA a entrada e uma peça longa perderia o final
 * em silêncio. O teto é o do próprio POST.
 */
final class SanitizadorDePecaDeTexto
{
    /** Comentário que o plugin `pagebreak` do TinyMCE grava. */
    private const QUEBRA_DE_PAGINA = '<!-- pagebreak -->';

    /** Marcador de texto (área de uso privado do Unicode) que atravessa o sanitizador no lugar do comentário. */
    private const MARCA_DA_QUEBRA = "\u{E000}bluejus-quebra-de-pagina\u{E000}";

    private const DIMENSOES = ['width', 'height'];

    private const ELEMENTOS = [
        'p' => [], 'br' => [], 'div' => [], 'span' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'code' => [], 'pre' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'hr' => [],
        'table' => ['border', 'cellpadding', 'cellspacing', ...self::DIMENSOES], 'caption' => [],
        'colgroup' => ['span'], 'col' => ['span', ...self::DIMENSOES],
        'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [...self::DIMENSOES],
        'td' => ['colspan', 'rowspan', 'scope', 'valign', ...self::DIMENSOES],
        'th' => ['colspan', 'rowspan', 'scope', 'valign', ...self::DIMENSOES],
        'a' => ['href', 'title', 'target', 'rel', 'id'],
        'img' => ['src', 'alt', 'title', ...self::DIMENSOES],
    ];

    /** Saem com o conteúdo: código, mídia embutida, formulário e cabeçalho de documento. */
    private const DESCARTADOS = [
        'script', 'style', 'head', 'title', 'meta', 'link', 'base', 'iframe', 'frame', 'frameset', 'object',
        'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'template',
        'noscript', 'noembed', 'noframes', 'audio', 'video', 'canvas', 'xmp', 'plaintext',
    ];

    private readonly HtmlSanitizerConfig $config;

    public function __construct(
        private readonly ReferenciasDePecaHtml $referencias = new ReferenciasDePecaHtml(),
    ) {
        $config = (new HtmlSanitizerConfig())
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->allowMediaSchemes([])
            ->allowRelativeMedias(true)
            ->withAttributeSanitizer(new SanitizadorDeEstiloInline())
            ->withMaxInputLength(-1);
        foreach (self::ELEMENTOS as $elemento => $atributos) {
            $config = $config->allowElement($elemento, ['style', 'align', ...$atributos]);
        }
        foreach (self::DESCARTADOS as $descartado) {
            $config = $config->dropElement($descartado);
        }
        $this->config = $config;
    }

    /** @param int|null $tenantId escritório dono da peça: imagem da subpasta de outro escritório sai */
    public function sanitizar(string $html, ?int $tenantId): string
    {
        $sanitizador = new HtmlSanitizer($this->config->withAttributeSanitizer($this->srcDeImagemDoEditor($tenantId)));
        $comMarca = (string) preg_replace('/<!--\s*pagebreak\s*-->/i', self::MARCA_DA_QUEBRA, $html);

        return str_replace(self::MARCA_DA_QUEBRA, self::QUEBRA_DE_PAGINA, trim($sanitizador->sanitize($comMarca)));
    }

    private function srcDeImagemDoEditor(?int $tenantId): AttributeSanitizerInterface
    {
        return new class ($this->referencias, $tenantId) implements AttributeSanitizerInterface {
            public function __construct(private readonly ReferenciasDePecaHtml $referencias, private readonly ?int $tenantId)
            {
            }

            /** @return list<string> */
            public function getSupportedElements(): array
            {
                return ['img'];
            }

            /** @return list<string> */
            public function getSupportedAttributes(): array
            {
                return ['src'];
            }

            public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
            {
                return $this->referencias->nomeDeImagemDoEscritorio($value, $this->tenantId) === null ? null : $value;
            }
        };
    }
}
