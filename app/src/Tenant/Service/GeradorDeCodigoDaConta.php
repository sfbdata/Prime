<?php

declare(strict_types=1);

namespace App\Tenant\Service;

/**
 * Gera o código da conta do escritório no formato do desenho (bj-contas.js, `codigoConta`): "BJ-XXXX-XXXX-XXXX", com 12
 * caracteres em 3 grupos de 4, num alfabeto sem as letras e os números ambíguos (sem I, O, 0 e 1). A unicidade é do
 * índice único da coluna; quem grava confere antes e tenta de novo na colisão.
 */
final class GeradorDeCodigoDaConta
{
    public const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const PADRAO = '/^BJ-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/';

    public function gerar(): string
    {
        $grupos = [];
        for ($g = 0; $g < 3; ++$g) {
            $grupo = '';
            for ($i = 0; $i < 4; ++$i) {
                $grupo .= self::ALFABETO[random_int(0, \strlen(self::ALFABETO) - 1)];
            }
            $grupos[] = $grupo;
        }

        return 'BJ-' . implode('-', $grupos);
    }
}
