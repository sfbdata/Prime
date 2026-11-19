<?php

declare(strict_types=1);

namespace App\Profile\Enum;

/**
 * Formato de data escolhido nas "Opções avançadas" do Meu Perfil (spec docs/specs/sistema-em-ingles.md, §2). Nulo
 * no perfil = automático, pelo idioma (o esqueleto do ICU em `FormatoDoIdioma`). O valor viaja à requisição
 * (`_bj_formato_data`) e ao `<html data-bj-formato-data>`; quem escreve é o `FormatoDoIdioma` (Shared), que guarda o
 * padrão de cada código, e o `BJFormato` no JS.
 */
enum FormatoDeData: string
{
    case DiaMesAno = 'dmy';
    case MesDiaAno = 'mdy';
    case AnoMesDia = 'ymd';

    /** O exemplo do seletor (31 de dezembro de 2026); número, igual em qualquer idioma. */
    public function exemplo(): string
    {
        return match ($this) {
            self::DiaMesAno => '31/12/2026',
            self::MesDiaAno => '12/31/2026',
            self::AnoMesDia => '2026-12-31',
        };
    }
}
