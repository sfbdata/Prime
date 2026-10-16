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
            new TwigFunction('papel_na_barra', $this->papelNaBarra(...)),
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

    /**
     * Subtítulo de papel sob o nome, na barra superior (desenho "05 - Perfis 1.0", linha 37):
     * "MASTER" para ROLE_SUPER_ADMIN; "USUÁRIO PRIMÁRIO" quando o vínculo com o escritório atual
     * tem o perfil de sistema; "USUÁRIO SECUNDÁRIO" quando tem outro perfil. As partes se juntam
     * com " · ". Roda em TODA página: nunca lança. Sem escritório na sessão sai só o que der
     * (o Master fora de escritório vê só "MASTER"; sem nada, string vazia).
     */
    public function papelNaBarra(): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return '';
        }

        $partes = [];
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            $partes[] = 'MASTER';
        }

        try {
            $vinculo = $this->tenantContext->getCurrentUserTenant();
        } catch (\Throwable) {
            $vinculo = null;
        }

        if ($vinculo !== null && $vinculo->isActive()) {
            $partes[] = $vinculo->getTenantRole()?->isSystem() === true
                ? 'USUÁRIO PRIMÁRIO'
                : 'USUÁRIO SECUNDÁRIO';
        }

        return implode(' · ', $partes);
    }
}
