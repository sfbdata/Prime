<?php

declare(strict_types=1);

namespace App\Master\Enum;

/**
 * Os seis status da divulgação numa seccional da OAB, com as chaves e os rótulos do desenho 05 (Perfis 1.2,
 * VS 21.10.26, L902, tooltip do chip): é o estado atual da UF, guardado na própria {@see \App\Master\Entity\SeccionalOab}
 * junto com a cor da paleta e a data (spec master-seccionais-oab, "Registro de divulgação").
 */
enum StatusDaDivulgacao: string
{
    case MaterialEnviado = 'env';
    case Reuniao         = 'vis';
    case Negociacao      = 'neg';
    case Parceria        = 'cli';
    case Retornar        = 'ret';
    case Convenio        = 'sem';

    public function rotulo(): string
    {
        return match ($this) {
            self::MaterialEnviado => 'Material enviado',
            self::Reuniao         => 'Reunião ou evento',
            self::Negociacao      => 'Em negociação',
            self::Parceria        => 'Parceria firmada',
            self::Retornar        => 'Retornar depois',
            self::Convenio        => 'Convênio feito',
        };
    }
}
