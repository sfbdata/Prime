<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * O filtro da chave "Audiências e intimações" do envio automático (spec push-envio-automatico §3;
 * dica do desenho: "Avisa quando chegar intimação ou designação de audiência"): a publicação é uma
 * intimação (pelo tipo de comunicação do DJEN) ou o texto dela designa audiência. Puro.
 */
final class FiltroDeAudienciasEIntimacoes
{
    public const INTIMACAO = 'intimação';
    public const AUDIENCIA = 'audiência';

    /**
     * "designo audiência", "designada a audiência", "audiência designada", "designação de audiência",
     * "fica designada audiência de conciliação" (o verbo antes ou depois, até 40 caracteres entre eles).
     */
    private const DESIGNACAO = '/\bdesign\w*\b.{0,40}?\baudi[eê]ncia|\baudi[eê]ncia\b.{0,40}?\bdesignad[ao]s?\b/iu';

    /** O que a publicação é para esta chave: intimação, designação de audiência ou nenhuma das duas (null). */
    public static function natureza(?string $tipoDeComunicacao, ?string $texto): ?string
    {
        if (preg_match('/intima[cç][aã]o/iu', (string) $tipoDeComunicacao) === 1) {
            return self::INTIMACAO;
        }
        if (preg_match(self::DESIGNACAO, (string) $texto) === 1) {
            return self::AUDIENCIA;
        }

        return null;
    }

    public static function casa(?string $tipoDeComunicacao, ?string $texto): bool
    {
        return self::natureza($tipoDeComunicacao, $texto) !== null;
    }
}
