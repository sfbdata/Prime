<?php

declare(strict_types=1);

namespace App\Profile\Enum;

/**
 * Gênero declarado pela pessoa no Meu Perfil (desenho 05 de 21/10, filtro Gênero; spec
 * docs/specs/meu-perfil-genero-atuacao.md). Nulo ou "prefiro não informar": fica de fora dos filtros.
 */
enum Genero: string
{
    case Feminino     = 'feminino';
    case Masculino    = 'masculino';
    case NaoInformado = 'nao_informado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Feminino     => 'Feminino',
            self::Masculino    => 'Masculino',
            self::NaoInformado => 'Prefiro não informar',
        };
    }
}
