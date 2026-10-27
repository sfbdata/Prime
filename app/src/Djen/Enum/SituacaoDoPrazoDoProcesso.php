<?php

declare(strict_types=1);

namespace App\Djen\Enum;

/** Onde o processo cai no "Acervo por jurisdição" (desenho 11), pela publicação mais recente dele. */
enum SituacaoDoPrazoDoProcesso: string
{
    case SemPrazo = 'sem';
    case Respondido = 'resp';
    case PrazoFindou = 'fin';
    case CienciaDada = 'cien';
    case PendenteDeCiencia = 'pend';

    /** Grupo que reúne os dois pendentes ("Pendentes de ciência ou de resposta"). */
    public const GRUPO_PENDENTES = 'pr';
    /** Dias em que um prazo cumprido ainda conta como "respondido". */
    public const DIAS_DE_RESPONDIDO = 10;

    /** @return list<string> chaves dos grupos, na ordem do desenho */
    public static function grupos(): array
    {
        return [self::GRUPO_PENDENTES, self::PendenteDeCiencia->value, self::CienciaDada->value, self::PrazoFindou->value, self::SemPrazo->value, self::Respondido->value];
    }

    public function pertenceAoGrupo(string $grupo): bool
    {
        return $grupo === self::GRUPO_PENDENTES
            ? $this === self::PendenteDeCiencia || $this === self::CienciaDada
            : $this->value === $grupo;
    }
}
