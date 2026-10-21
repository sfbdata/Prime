<?php

declare(strict_types=1);

namespace App\Agenda\Enum;

/** O que o escritório fez com uma data do sistema pelo botão direito da Agenda (desenho 09). */
enum TipoDeAjuste: string
{
    /** Data nova (feriado municipal, suspensão do tribunal...), com nome, calendário e fonte. */
    case Acrescentar = 'acrescentar';
    /** Novo nome para uma data do sistema. */
    case Renomear = 'renomear';
    /** Data do sistema que some da Agenda do escritório. */
    case Ocultar = 'ocultar';
}
