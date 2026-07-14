<?php

declare(strict_types=1);

namespace App\Sync\Exception;

/**
 * Lançada quando se pede o client do Drive de um tenant que não tem conexão ativa/pronta
 * ({@see \App\Sync\Entity\TenantDriveConexao}). Os chamadores tratam como no-op: o dispatch/handler
 * do sync ignora, e o comando de reconciliação pula o tenant.
 */
final class TenantSemDriveException extends \RuntimeException
{
    public static function paraTenant(int $tenantId): self
    {
        return new self(sprintf('Tenant %d não tem conexão de Drive ativa.', $tenantId));
    }
}
