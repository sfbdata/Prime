<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

/**
 * A soma das linhas de dado NÃO bate com o totalizador da própria planilha (SPEC espelho §4.3).
 *
 * É a prova mais barata que existe de que o leitor leu certo, e por isso é **portão, não relatório**:
 * se ela não fecha, o leitor está errado, e nenhuma conferência feita em cima desse espelho valeria
 * nada. Gravar assim mesmo seria gravar lixo com aparência de verdade — que é exatamente a classe de
 * defeito que esta frente existe para consertar.
 */
final class ReconciliacaoInternaFalhouException extends \RuntimeException
{
    /**
     * @param array<string, int> $soma  o que o leitor somou nas linhas de dado
     * @param array<string, int> $rodape o que a planilha declara no totalizador
     */
    public static function comDivergencia(string $arquivo, array $soma, array $rodape): self
    {
        $linhas = [];

        foreach ($soma as $campo => $valor) {
            $declarado = $rodape[$campo] ?? null;

            if ($valor !== $declarado) {
                $linhas[] = sprintf(
                    '%s: linhas somam %s, rodapé declara %s (diferença %s)',
                    $campo,
                    number_format($valor / 100, 2, ',', '.'),
                    $declarado === null ? 'nada' : number_format($declarado / 100, 2, ',', '.'),
                    $declarado === null ? '—' : number_format(($valor - $declarado) / 100, 2, ',', '.'),
                );
            }
        }

        return new self(sprintf(
            "A soma das linhas não bate com o totalizador da planilha \"%s\". Nada foi gravado.\n%s",
            $arquivo,
            implode("\n", $linhas),
        ));
    }
}
