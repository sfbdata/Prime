<?php
declare(strict_types=1);

namespace App\Twig;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TenantContextExtension extends AbstractExtension
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly Security $security,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_tenant', $this->currentTenant(...)),
            new TwigFunction('meus_escritorios', $this->meusEscritorios(...)),
        ];
    }

    public function currentTenant(): ?Tenant
    {
        return $this->tenantContext->getCurrentTenant();
    }

    /**
     * Vínculos ativos do usuário logado, para o seletor de escritórios no header.
     *
     * @return UserTenant[]
     */
    public function meusEscritorios(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $this->userTenantRepository->findActiveByUser($user);
    }
}
