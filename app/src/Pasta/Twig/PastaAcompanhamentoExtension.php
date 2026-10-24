<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaAcompanhamentoDesligadoRepository;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `pasta_acompanhada(pasta)`: o estado do item "Acompanhar alterações" no menu ⋮ da pasta, para
 * o USUÁRIO LOGADO no escritório da sessão. Molde: {@see PastaFavoritaExtension}.
 *
 * O padrão é ligado: verdadeiro enquanto não houver a linha de "desligado". Sem usuário, sem
 * escritório ou com pasta de outro escritório, ninguém acompanha: devolve falso.
 */
final class PastaAcompanhamentoExtension extends AbstractExtension
{
    public function __construct(
        private readonly PastaAcompanhamentoDesligadoRepository $repository,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pasta_acompanhada', $this->pastaAcompanhada(...)),
        ];
    }

    public function pastaAcompanhada(Pasta $pasta): bool
    {
        $usuario = $this->security->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        if (!$usuario instanceof User || $tenant === null || $pasta->getTenant() !== $tenant) {
            return false;
        }

        return !$this->repository->estaDesligado($pasta, $usuario, $tenant);
    }
}
