<?php

declare(strict_types=1);

namespace App\Central\Twig;

use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `central_disponivel()` — se o lançador flutuante da Central entra na página: usuário logado com vínculo ATIVO no
 * escritório da sessão (a mesma regra da Central, D3). Roda em TODA página do layout, por isso nunca lança: qualquer
 * falha vira "não mostra", e a página segue (o mesmo cuidado da extensão da IA — HANDOFF §8).
 */
final class CentralExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly AcessoACentral $acesso,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('central_disponivel', $this->disponivel(...))];
    }

    public function disponivel(): bool
    {
        try {
            $usuario = $this->security->getUser();
            $tenant = $this->tenantContext->getCurrentTenant();

            return $usuario instanceof User && $tenant !== null && $this->acesso->temVinculoAtivo($usuario, $tenant);
        } catch (\Throwable) {
            return false;
        }
    }
}
