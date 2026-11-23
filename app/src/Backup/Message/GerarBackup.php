<?php

declare(strict_types=1);

namespace App\Backup\Message;

/**
 * Pede ao worker que monte o ZIP de um pedido de backup. Só o id: o worker recarrega pedido, pessoa e escritório pelo
 * banco (spec backup-e-exportacao-de-dados, seção 2, regra 3).
 */
final readonly class GerarBackup
{
    public function __construct(public int $pedidoId)
    {
    }
}
