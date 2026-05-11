<?php
declare(strict_types=1);

namespace App\Twig;

use App\Entity\Tenant\Tenant;
use App\Service\Tenant\TenantContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TenantContextExtension extends AbstractExtension
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_tenant', $this->currentTenant(...)),
        ];
    }

    public function currentTenant(): ?Tenant
    {
        return $this->tenantContext->getCurrentTenant();
    }
}
