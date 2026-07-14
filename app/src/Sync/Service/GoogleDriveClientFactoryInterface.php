<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\Tenant\Tenant;
use App\Sync\DTO\DriveDoTenant;
use App\Sync\Exception\TenantSemDriveException;

/**
 * Resolve o client do Google Drive de UM tenant a partir da sua {@see \App\Sync\Entity\TenantDriveConexao}.
 * Abstração para os testes trocarem por um fake (o motor e o handler dependem desta interface, não do
 * client global). Multi-tenant: cada chamada devolve o client autenticado só daquele escritório.
 */
interface GoogleDriveClientFactoryInterface
{
    /**
     * @throws TenantSemDriveException se o tenant não tem conexão ativa/pronta.
     */
    public function paraTenant(Tenant $tenant): DriveDoTenant;
}
