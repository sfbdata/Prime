<?php

declare(strict_types=1);

namespace App\Voto\Service;

/**
 * Aviso da soma das frações da lista (desenho, revisão: "o esperado é 1 (ou 100%)"). A lista inteira, aptas e não
 * aptas, deve fechar 1 (tolerância 0,005) ou 100 (tolerância 0,5); fora disso o gestor confere a planilha. Só avisa:
 * não impede de publicar.
 */
final class SomaDasFracoes
{
    /** @param string $soma decimal com ponto ("1.000000") */
    public static function foraDoEsperado(string $soma): bool
    {
        return !self::proxima($soma, '1', '0.005') && !self::proxima($soma, '100', '0.5');
    }

    private static function proxima(string $soma, string $alvo, string $tolerancia): bool
    {
        $diferenca = bcsub($soma, $alvo, 6);
        if (bccomp($diferenca, '0', 6) < 0) {
            $diferenca = bcmul($diferenca, '-1', 6);
        }

        return bccomp($diferenca, $tolerancia, 6) < 0;
    }
}
