<?php

declare(strict_types=1);

namespace App\Conector\Service;

/**
 * O HTML de e-mail como texto puro, para o que sai pelo conector de I.A. (`docs/specs/mcp-remoto-oauth.md`, 6.9: a
 * prévia do modelo sai em texto, nunca em HTML). Tira cabeça, estilo e script com o conteúdo, põe quebra de linha nos
 * blocos, mantém o endereço do link entre parênteses, apaga as demais marcas, decodifica as entidades e junta as linhas
 * em branco repetidas. O {@see \App\Comercial\Service\MontadorDeEmail} tem a versão texto do e-mail, mas ela é privada
 * e fica no domínio do Comercial, que esta ferramenta não altera.
 */
final class HtmlParaTextoDoConector
{
    private function __construct()
    {
    }

    public static function converter(string $html): string
    {
        $semCabeca = (string) preg_replace('{<(head|style|script)\b.*?</\1>}is', '', $html);
        $semComentarios = (string) preg_replace('{<!--.*?-->}s', '', $semCabeca);
        $comQuebras = (string) preg_replace('{<br\s*/?>|</(p|div|tr|li|h[1-6]|table|ul|ol|blockquote)>}i', "\n", $semComentarios);
        // O link sai só com o texto, sem o endereço: o rodapé leva o link assinado do descadastro (6.9, LGPD).
        $texto = html_entity_decode(strip_tags($comQuebras), \ENT_QUOTES | \ENT_HTML5);
        $linhas = array_map(static fn (string $l): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $texto));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $linhas)));
    }
}
