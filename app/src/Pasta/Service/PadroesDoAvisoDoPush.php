<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\Enum\ChaveDoAvisoDoPush;

/**
 * O valor de cada chave do "Avisar quando chegar" para a pasta que nunca mexeu nelas (sem linha em
 * `pasta_aviso_push`). Único lugar da regra: trocar um padrão é mudar uma constante.
 *
 *   - Responsável da pasta e Por e-mail: LIGADAS, como no desenho (D1, coordenador, 19/10/2026);
 *   - Análise da BlueJus IA: DESLIGADA, cada escritório liga por pasta. Decidido pelo dono
 *     (19/10/2026): desvio do desenho aprovado (o desenho nasce com as três ligadas; a IA ligada
 *     geraria chamadas de IA em toda publicação nova). Spec push-avisar-quando-chegar D2.
 */
final class PadroesDoAvisoDoPush
{
    public const RESPONSAVEL = true;
    public const EMAIL = true;
    /** Decidido pelo dono (19/10/2026): desvio do desenho aprovado (spec push-avisar-quando-chegar D2). */
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
