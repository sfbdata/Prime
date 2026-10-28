<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Troca as variáveis `{cliente}`, `{cpf}`... do corpo de um modelo pelos dados da pasta (desenho 02 de
 * 27/10, `bj-modelos.js` `vars`, `preencher` e `fmtPeca`). Variável sem dado vira `___` para a pessoa
 * completar. O nome do cliente sai em negrito e maiúsculas, onde quer que apareça no TEXTO (nunca dentro de uma tag). Os valores entram
 * escapados: dado da pasta nunca vira HTML.
 */
final class PreenchedorDeModelo
{
    public const LACUNA = '___';
    private const LIMITE_DO_VALOR = 500;

    /** As variáveis que o sistema sabe preencher a partir da pasta, na ordem da dica do editor. */
    public const CONHECIDAS = ['cliente', 'cpf', 'processo', 'pasta', 'cidade', 'data', 'advogado', 'oab'];

    /** @return list<string> as chaves distintas usadas no corpo, na ordem em que aparecem */
    public static function chavesDo(string $corpo): array
    {
        preg_match_all('/\{(\w+)\}/', $corpo, $achados);

        return array_values(array_unique($achados[1]));
    }

    /** @param array<string, string> $valores chave => valor (vazio ou ausente vira a lacuna) */
    public function preencher(string $corpo, array $valores): string
    {
        $html = (string) preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($valores): string {
            $valor = $this->limpo($valores[$m[1]] ?? '');

            return $valor === '' ? self::LACUNA : htmlspecialchars($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $corpo);

        $cliente = $this->limpo($valores['cliente'] ?? '');
        if ($cliente === '' || $cliente === self::LACUNA) {
            return $html;
        }
        $escapado  = htmlspecialchars($cliente, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $negrito   = '<b>' . htmlspecialchars(mb_strtoupper($cliente), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</b>';
        $pedacos   = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        // Posições ímpares são as tags; o negrito só entra no texto, nunca dentro de `<tag attr>`.
        foreach ($pedacos as $i => $pedaco) {
            if ($i % 2 === 0) {
                $pedacos[$i] = str_replace($escapado, $negrito, $pedaco);
            }
        }

        return implode('', $pedacos);
    }

    private function limpo(string $valor): string
    {
        return mb_substr(trim($valor), 0, self::LIMITE_DO_VALOR);
    }
}
