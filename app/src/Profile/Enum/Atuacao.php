<?php

declare(strict_types=1);

namespace App\Profile\Enum;

/**
 * Atuação declarada pela pessoa no Meu Perfil (desenho 05 de 21/10, filtro Cargo: adv, est, ctl, fin, adm, out; spec
 * docs/specs/meu-perfil-genero-atuacao.md). O sistema só SUGERE (SugestaoDeAtuacao); quem grava é a pessoa.
 */
enum Atuacao: string
{
    case Advogado       = 'advogado';
    case Estagiario     = 'estagiario';
    case Controladoria  = 'controladoria';
    case Financeiro     = 'financeiro';
    case Administrativo = 'administrativo';
    case Outra          = 'outra';

    public function rotulo(): string
    {
        return match ($this) {
            self::Advogado       => 'Advogado(a)',
            self::Estagiario     => 'Estagiário(a)',
            self::Controladoria  => 'Controladoria',
            self::Financeiro     => 'Financeiro',
            self::Administrativo => 'Administrativo',
            self::Outra          => 'Outra',
        };
    }
}
