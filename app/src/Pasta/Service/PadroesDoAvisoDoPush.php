<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\Enum\ChaveDoAvisoDoPush;

/**
 * O valor de cada chave do "Avisar quando chegar" para a pasta que nunca mexeu nelas (sem linha em
 * `pasta_aviso_push`). Único lugar da regra: trocar um padrão é mudar uma constante.
 *
 *   - Responsável da pasta e Por e-mail: LIGADAS, como no desenho (D1, coordenador, 19/10/2026);
 *   - Análise da BlueJus IA: DESLIGADA. A CONFIRMAR COM O DONO (D2): ligada por padrão, o deploy
 *     dispararia uma análise de IA em toda publicação nova de todas as pastas no mesmo dia.
 */
final class PadroesDoAvisoDoPush
{
    public const RESPONSAVEL = true;
    public const EMAIL = true;
    /** A confirmar com o dono (spec push-avisar-quando-chegar D2). */
    public const IA = false;

    public static function de(ChaveDoAvisoDoPush $chave): bool
    {
        return match ($chave) {
            ChaveDoAvisoDoPush::Responsavel => self::RESPONSAVEL,
            ChaveDoAvisoDoPush::Email => self::EMAIL,
            ChaveDoAvisoDoPush::Ia => self::IA,
        };
    }
}
