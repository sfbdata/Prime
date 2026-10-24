<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Shared\Service\SanitizadorTextoRico;

/**
 * O despacho vem de um campo simples (desenho 04B de 22/10, L60), mas continua guardado como HTML saneado (C10),
 * para as mensagens antigas (editor rico) e as novas aparecerem iguais na linha do tempo: o texto é escapado,
 * cada bloco separado por linha em branco vira um parágrafo e cada quebra simples vira `<br>`.
 */
final class TextoDoDespacho
{
    public function __construct(
        private readonly SanitizadorTextoRico $sanitizador,
    ) {
    }

    /** Quebras normalizadas para `\n` e pontas aparadas: é sobre esta forma que o limite de 5.000 conta. */
    public static function normalizar(string $texto): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $texto));
    }

    public function paraHtml(string $texto): string
    {
        $paragrafos = preg_split('/\n\s*\n/u', self::normalizar($texto)) ?: [];
        $html = '';
        foreach ($paragrafos as $paragrafo) {
            $linhas = array_map(
                static fn (string $linha): string => htmlspecialchars($linha, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                explode("\n", trim($paragrafo)),
            );
            $html .= '<p>' . implode('<br>', $linhas) . '</p>';
        }

        return (string) $this->sanitizador->limpar($html);
    }
}
