<?php

declare(strict_types=1);

namespace App\Voto\Twig;

use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\Voto\Service\AcessoAAssembleia;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Função Twig `voto_gere_equipe()`: a pessoa logada é a Administração do escritório da sessão (Master ou primário ativo)?
 * Só ela vê a entrada "Perfis" (o desenho a mostra só para o perfil `admin`). A decisão de verdade continua no
 * controller e nos UseCases; isto só esconde o atalho.
 */
final class EquipeExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly AcessoAAssembleia $acesso,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('voto_gere_equipe', $this->gereEquipe(...))];
    }

    public function gereEquipe(): bool
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        return $usuario instanceof User && null !== $tenant && $this->acesso->ehAdministracao($usuario, $tenant);
    }
}
