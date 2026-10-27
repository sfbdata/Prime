<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Comercial\Campanha\SanitizadorDeEstiloInline;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Limpa o corpo (HTML rico) de um modelo de petição ao gravar. O editor da tela é do navegador e não
 * merece confiança: o servidor deixa só o que uma peça precisa (parágrafo, ênfase, título, lista, tabela,
 * citação) e o `style` passa pelo {@see SanitizadorDeEstiloInline}. Sem script, iframe, formulário,
 * link nem imagem; imagem colada (data:) não é mantida no M1.
 */
final class SanitizadorDeModeloDePeca
{
    public const LIMITE_EM_BYTES = 262144;

    private const ELEMENTOS = [
        'p' => [], 'br' => [], 'div' => [], 'span' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'hr' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
    ];

    private readonly HtmlSanitizer $sanitizador;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->withAttributeSanitizer(new SanitizadorDeEstiloInline())
            ->withMaxInputLength(self::LIMITE_EM_BYTES);
        foreach (self::ELEMENTOS as $elemento => $atributos) {
            $config = $config->allowElement($elemento, ['style', 'align', ...$atributos]);
        }
        foreach (['script', 'style', 'head', 'title', 'iframe', 'form', 'object', 'embed'] as $caido) {
            $config = $config->dropElement($caido);
        }
        $this->sanitizador = new HtmlSanitizer($config);
    }

    public function sanitizar(string $html): string
    {
        return trim($this->sanitizador->sanitize($html));
    }

    /** Sem texto visível (só tags e espaços): o modelo não tem corpo. */
    public static function estaVazio(string $html): bool
    {
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim(str_replace("\u{00A0}", ' ', $texto)) === '';
    }
}
