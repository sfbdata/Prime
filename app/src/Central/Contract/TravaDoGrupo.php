<?php

declare(strict_types=1);

namespace App\Central\Contract;

use App\Central\Entity\Conversa;

/**
 * Serializa as mudanças de participação de UM grupo (adicionar, readmitir): sem ela, duas readmissões da mesma pessoa
 * passam juntas pela checagem "já participa?" e o grupo ganha duas linhas "adicionou" e dois avisos; e o teto de
 * participantes pode ser ultrapassado. Exige transação aberta (a trava só segura até o commit).
 */
interface TravaDoGrupo
{
    public function travar(Conversa $grupo): void;
}
