<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

/**
 * O texto de uma peça como o editor da tela o guarda: HTML. O projeto não tem biblioteca de Markdown (decisão do
 * orquestrador, PR 12), então a entrada aceita duas formas:
 *  - HTML (há pelo menos uma tag): segue como veio, e o saneador do UseCase tira script, atributo `on*` e o que não vale;
 *  - texto simples (nenhuma tag): tudo é escapado, cada bloco separado por linha em branco vira um `<p>` e a quebra
 *    simples dentro do bloco vira `<br>`.
 */
final class DocumentosEPecasConteudo
{
    private const TAG = '/<\/?[a-zA-Z][a-zA-Z0-9]*(\s[^<>]*)?\/?>/';

    private function __construct()
    {
    }

    public static function emHtml(string $conteudo): string
    {
        if (preg_match(self::TAG, $conteudo) === 1) {
            return $conteudo;
        }
        $texto = str_replace(["\r\n", "\r"], "\n", trim($conteudo));
        $blocos = preg_split('/\n[ \t]*\n+/', $texto) ?: [];
        $paragrafos = [];
        foreach ($blocos as $bloco) {
            $bloco = trim($bloco);
            if ($bloco !== '') {
                $paragrafos[] = '<p>' . str_replace("\n", '<br>', htmlspecialchars($bloco, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
            }
        }

        return implode("\n", $paragrafos);
    }
}
