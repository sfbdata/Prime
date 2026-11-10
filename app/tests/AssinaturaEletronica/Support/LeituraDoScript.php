<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Support;

/**
 * A leitura do texto dos scripts da tela (o container não tem node), a mesma do `TelaReforcadaJsTest`: o corpo de uma
 * função e o esqueleto HTML do último `return` dela (só os textos entre aspas simples fora de parênteses).
 */
trait LeituraDoScript
{
    private static function ler(string $arquivo): string
    {
        $texto = file_get_contents($arquivo);
        self::assertIsString($texto, $arquivo);

        return $texto;
    }

    /** O corpo da `function <nome>(...) { ... }`, sem as chaves de fora (os textos entre aspas não contam chave). */
    private static function corpo(string $js, string $nome): string
    {
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertIsInt($inicio, 'faltou a function ' . $nome);
        $abre = strpos($js, '{', $inicio);
        self::assertIsInt($abre);
        $profundidade = 0;
        for ($i = $abre, $n = \strlen($js); $i < $n; ++$i) {
            $c = $js[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $i = self::fimDoTexto($js, $i);
                continue;
            }
            if ($c === '{') {
                ++$profundidade;
            } elseif ($c === '}' && --$profundidade === 0) {
                return substr($js, $abre + 1, $i - $abre - 1);
            }
        }
        self::fail('a function ' . $nome . ' não fecha');
    }

    /**
     * O HTML do último `return` da função, com as chamadas listadas em `$embutir` trocadas pelo HTML dado.
     *
     * @param array<string, string> $embutir
     */
    private static function esqueleto(string $js, string $funcao, array $embutir = []): string
    {
        $corpo = self::corpo($js, $funcao);
        $expressao = substr($corpo, (int) strrpos($corpo, 'return ') + 7);
        $html = '';
        $profundidade = 0;
        for ($i = 0, $n = \strlen($expressao); $i < $n; ++$i) {
            $c = $expressao[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $fim = self::fimDoTexto($expressao, $i);
                if ($profundidade === 0 && $c === "'") {
                    $html .= stripslashes(substr($expressao, $i + 1, $fim - $i - 1));
                }
                $i = $fim;
                continue;
            }
            if ($profundidade === 0 && $c === ';') {
                break;
            }
            if ($profundidade === 0 && preg_match('/\G([A-Za-z_]\w*)\(\)/', $expressao, $m, 0, $i) === 1 && isset($embutir[$m[1]])) {
                $html .= $embutir[$m[1]];
                $i += \strlen($m[0]) - 1;
                continue;
            }
            $profundidade += match ($c) {
                '(', '[', '{' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };
        }

        return $html;
    }

    private static function fimDoTexto(string $js, int $abre): int
    {
        $aspa = $js[$abre];
        for ($i = $abre + 1, $n = \strlen($js); $i < $n; ++$i) {
            if ($js[$i] === '\\') {
                ++$i;
            } elseif ($js[$i] === $aspa) {
                return $i;
            }
        }
        self::fail('texto sem fim');
    }
}
