<?php

declare(strict_types=1);

namespace App\Tenant\Enum;

/** Por que a pessoa deixou de ter vínculo com o escritório (spec docs/specs/saida-da-equipe.md). */
enum MotivoDaSaida: string
{
    /** Removida por quem administra a equipe (tela de equipe ou "Demitir" da equipe da conta). */
    case Removido = 'removido';
    /** Saiu por conta própria ("Sair do escritório"). */
    case Saiu = 'saiu';
}
