<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Limpa o HTML importado de uma campanha (spec `comercial-e-emails.md` §8.3, A11): tags de e-mail com estilo inline;
 * sem script, iframe, form, object nem eventos; links só http(s) e mailto, imagens só http(s); o `style` passa pelo
 * {@see SanitizadorDeEstiloInline}. `<head>` e `<style>` caem (a tela avisa com {@see self::AVISO_DE_ESTILO}).
 *
 * Roda ao salvar e em TODA montagem (prévia, teste, "Baixar HTML", envio). O `{nome}` é trocado depois, pelo nome já
 * escapado, por quem monta. O documento inteiro (`<html><body>`) vira só o conteúdo do `<body>`: o sanitizador
 * trabalha num fragmento, e um documento inteiro sairia vazio. O estilo do `<body>` vai para um `<div>` em volta.
 */
final class SanitizadorDeHtmlDeEmail
{
    public const LIMITE_EM_BYTES = 204800;
    public const AVISO_DE_ESTILO = 'Estilos em bloco (<style>) não são mantidos; use estilos na própria tag.';

    private const COMUNS = ['style', 'align', 'valign', 'width', 'height', 'bgcolor', 'border', 'dir', 'lang', 'title', 'role'];
    private const ELEMENTOS = [
        'table' => ['cellpadding', 'cellspacing'], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'], 'caption' => [], 'div' => [], 'span' => [],
        'p' => [], 'br' => [], 'hr' => [], 'a' => ['href'], 'img' => ['src', 'alt'], 'h1' => [], 'h2' => [], 'h3' => [],
        'h4' => [], 'h5' => [], 'h6' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'small' => [], 'sub' => [], 'sup' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
        'center' => [], 'font' => ['color', 'face', 'size'], 'pre' => [], 'code' => [],
    ];

    private readonly HtmlSanitizer $sanitizador;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->withAttributeSanitizer(new SanitizadorDeEstiloInline())
            ->withMaxInputLength(self::LIMITE_EM_BYTES * 2);
        foreach (self::ELEMENTOS as $elemento => $atributos) {
            $config = $config->allowElement($elemento, [...self::COMUNS, ...$atributos]);
        }
        foreach (['script', 'style', 'head', 'title', 'iframe', 'form', 'object', 'embed'] as $caido) {
            $config = $config->dropElement($caido);
        }
        $this->sanitizador = new HtmlSanitizer($config);
    }

    public function sanitizar(string $html): string
    {
        return $this->sanitizador->sanitize(self::conteudoDoCorpo($html));
    }

    /** Se o HTML traz estilo em bloco ou cabeçalho, que a sanitização tira (a tela mostra o aviso). */
    public static function temEstiloEmBloco(string $html): bool
    {
        return preg_match('/<(style|head)\b/i', $html) === 1;
    }

    /** O `<title>` do documento, para o nome e o assunto do modelo importado (dc L.553). */
    public static function titulo(string $html): ?string
    {
        if (preg_match('/<title>([^<]*)/i', $html, $achado) !== 1) {
            return null;
        }
        $titulo = trim(html_entity_decode($achado[1], ENT_QUOTES | ENT_HTML5));

        return $titulo === '' ? null : $titulo;
    }

    private static function conteudoDoCorpo(string $html): string
    {
        $semCabeca = (string) preg_replace('{<head\b.*?</head\s*>}is', '', $html);
        if (preg_match('{<body\b([^>]*)>(.*?)(?:</body\s*>|$)}is', $semCabeca, $corpo) === 1) {
            $estilo = preg_match('/\bstyle\s*=\s*"([^"]*)"/i', $corpo[1], $s) === 1 ? $s[1] : null;

            return $estilo === null ? $corpo[2] : '<div style="' . $estilo . '">' . $corpo[2] . '</div>';
        }

        return (string) preg_replace('{<!doctype[^>]*>|</?html\b[^>]*>}i', '', $semCabeca);
    }
}
