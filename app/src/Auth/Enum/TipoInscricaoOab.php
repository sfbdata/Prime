<?php

declare(strict_types=1);

namespace App\Auth\Enum;

/**
 * Principal: seccional do domicílio profissional, uma só. Suplementar: para atuar com habitualidade
 * em outro estado, uma por seccional (desenho "07 - Meu Perfil", VS 18.10.26, ofTipoAj).
 */
enum TipoInscricaoOab: string
{
    case Principal   = 'principal';
    case Suplementar = 'suplementar';

    public function rotulo(): string
    {
        return $this === self::Principal ? 'Principal' : 'Suplementar';
    }

    public function ajuda(): string
    {
        return $this === self::Principal
            ? 'Seccional do seu domicílio profissional. Só pode haver uma; se você mudar, a atual passa a suplementar.'
            : 'Para atuar com habitualidade em outro estado. Uma por seccional.';
    }
}
