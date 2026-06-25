<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use App\Entity\Tenant\Tenant;

/**
 * Marca uma entidade como pertencente a um tenant (escritório). O TenantFilter aplica
 * automaticamente `tenant_id = :tenant` nas queries de entidades que implementam esta
 * interface. Toda entidade de negócio escopada por escritório deve implementá-la e ter uma
 * relação ManyToOne(Tenant) com JoinColumn(nullable: false).
 */
interface TenantAware
{
    public function getTenant(): ?Tenant;
}
