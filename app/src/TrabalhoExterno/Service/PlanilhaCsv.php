<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

/**
 * O CSV das exportações do Trabalho Externo (`q2` do desenho 06): `;` entre campos, todos entre aspas
 * com as aspas dobradas, uma linha por `\n` e o BOM do UTF-8 na frente para o Excel abrir os acentos.
 * Campo que o Excel leria como fórmula (começa com `=`, ou com `+`, `-` ou `@` sem ser telefone nem
 * @usuário) ganha um apóstrofo na frente: sem isso, o Excel executaria o que a pessoa digitou.
 */
final class PlanilhaCsv
{
    /** @param list<list<string>> $linhas a primeira é o cabeçalho */
    public static function documento(array $linhas): string
    {
        return "\u{FEFF}" . implode("\n", array_map(self::linha(...), $linhas));
    }

    /** @param list<string> $campos */
    public static function linha(array $campos): string
    {
        return implode(';', array_map(static function (string $v): string {
            $formula = preg_match('/^[=\t\r]/', $v) === 1
                || (preg_match('/^[+\-@]/', $v) === 1 && preg_match('/^(\+?[\d\s().-]+|@[\w.]+)$/u', $v) !== 1);
            $v = $formula ? "'" . $v : $v;

            return '"' . str_replace('"', '""', $v) . '"';
        }, $campos));
    }
}
