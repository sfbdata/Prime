<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TenantContext
{
    private const SESSION_KEY = 'current_tenant_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getCurrentTenant(): ?Tenant
    {
        $tenantId = $this->tenantIdFromSession();
        if ($tenantId === null) {
            return null;
        }

        return $this->em->find(Tenant::class, $tenantId);
    }

    public function getCurrentUserTenant(): ?UserTenant
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $tenantId = $this->tenantIdFromSession();
        if ($tenantId === null) {
            return null;
        }

        return $this->em->getRepository(UserTenant::class)->findOneBy([
            'user'   => $user,
            'tenant' => $this->em->getReference(Tenant::class, $tenantId),
        ]);
    }

    public function setCurrentTenant(int $tenantId): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('Usuário não autenticado.');
        }

        $userTenant = $this->em->getRepository(UserTenant::class)->findOneBy([
            'user'   => $user,
            'tenant' => $this->em->getReference(Tenant::class, $tenantId),
        ]);

        if ($userTenant === null || !$userTenant->isActive()) {
            throw new AccessDeniedException('Sem vínculo ativo com este escritório.');
        }

        $this->requestStack->getSession()->set(self::SESSION_KEY, $tenantId);
    }

    public function hasCurrentTenant(): bool
    {
        return $this->tenantIdFromSession() !== null;
    }

    public function clearCurrentTenant(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }

    private function tenantIdFromSession(): ?int
    {
        try {
            $value = $this->requestStack->getSession()->get(self::SESSION_KEY);
        } catch (SessionNotFoundException) {
            return null;
        }

        return is_int($value) ? $value : null;
    }
}
