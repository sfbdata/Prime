<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Tipo do vínculo temporal entre uma Pessoa e um Objeto de Cobrança (SPEC §7).
 * Pessoa vinculada não é automaticamente cliente nem automaticamente devedor.
 */
enum TipoVinculo: string
{
    case Proprietario = 'proprietario';
    case Coproprietario = 'coproprietario';
    case Inquilino = 'inquilino';
    case Ocupante = 'ocupante';
    case Possuidor = 'possuidor';
    case Representante = 'representante';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Proprietario => 'Proprietário',
            self::Coproprietario => 'Coproprietário',
            self::Inquilino => 'Inquilino',
            self::Ocupante => 'Ocupante',
            self::Possuidor => 'Possuidor',
            self::Representante => 'Representante',
            self::Outro => 'Outro',
        };
    }
}
