<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Ciclo de vida do Caso de Cobrança (SPEC §17). As fases da SPEC §16 mapeiam 1:1:
 * `ativo` = extrajudicial, `judicializado` = judicializada, `encerrado` = encerrada.
 * "Pronto para encerrar" NÃO é valor deste enum — é indicador derivado (saldo exigível
 * zero e caso não encerrado), pois um caso pode estar judicializado E pronto para encerrar.
 */
enum StatusCaso: string
{
    case Ativo = 'ativo';
    case Judicializado = 'judicializado';
    case Encerrado = 'encerrado';

    public function label(): string
    {
        return match ($this) {
            self::Ativo => 'Ativo',
            self::Judicializado => 'Judicializado',
            self::Encerrado => 'Encerrado',
        };
    }

    /** Classe Bootstrap `text-bg-*` para o badge de estado (apresentação, Etapa 8). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Ativo => 'text-bg-primary',
            self::Judicializado => 'text-bg-warning',
            self::Encerrado => 'text-bg-secondary',
        };
    }
}
