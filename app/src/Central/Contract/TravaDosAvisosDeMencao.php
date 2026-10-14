<?php

declare(strict_types=1);

namespace App\Central\Contract;

use App\Entity\Auth\User;

/**
 * Serializa, por destinatário, a checagem "já há aviso de menção não lido?" e a gravação do aviso
 * (`docs/specs/central-comunicacao-a2.md` §2). Sem ela, dois envios no mesmo instante passam juntos pela
 * checagem e a pessoa ganha dois avisos.
 *
 * Quem chama segura a trava até o commit — por isso exige transação aberta.
 */
interface TravaDosAvisosDeMencao
{
    public function travar(User $destinatario): void;
}
