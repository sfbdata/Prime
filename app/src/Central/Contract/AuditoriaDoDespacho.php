<?php

declare(strict_types=1);

namespace App\Central\Contract;

use App\Central\Enum\PrioridadeDoDespacho;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;

/**
 * A linha de auditoria do despacho: quem levou qual item de qual pasta para qual conversa, SEM texto (regra 3 da
 * Central: o `audit_log` é lido por quem administra o escritório). Só persiste: o `flush` é do UseCase, dentro da
 * transação do despacho (falhou aqui, não fica mensagem).
 */
interface AuditoriaDoDespacho
{
    public function registrar(
        User $autor,
        Tenant $tenant,
        Pasta $pasta,
        TipoDeItemEncaminhado $tipo,
        ?int $itemId,
        int $conversaId,
        PrioridadeDoDespacho $prioridade,
    ): void;
}
