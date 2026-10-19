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
 * Central: o `audit_log` é lido por quem administra o escritório). Fecha a unidade de trabalho do despacho: grava a
 * linha e o que estiver pendente (os avisos de "Urgente") no mesmo `flush`.
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
