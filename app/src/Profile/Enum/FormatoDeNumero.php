<?php

declare(strict_types=1);

namespace App\Profile\Enum;

/**
 * Forma de escrever números e valores, escolhida nas "Opções avançadas" do Meu Perfil (spec
 * docs/specs/sistema-em-ingles.md, §2). Nulo no perfil = automático, pelo idioma. A moeda continua sendo o real:
 * muda só o separador (`R$ 1.234,56` ou `R$ 1,234.56`).
 */
enum FormatoDeNumero: string
{
    case Brasil = 'br';
    case EstadosUnidos = 'us';

    /** O exemplo do seletor; número, igual em qualquer idioma. */
    public function exemplo(): string
    {
        return match ($this) {
            self::Brasil        => '1.234,56',
            self::EstadosUnidos => '1,234.56',
        };
    }
}
