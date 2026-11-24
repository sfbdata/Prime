<?php

declare(strict_types=1);

namespace App\Produto\Twig;

use App\Entity\Auth\User;
use App\Produto\DTO\FerramentaDoCartaoOutput;
use App\Produto\UseCase\ListarFerramentasDoEscritorioUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `ferramentas_do_escritorio()`: as linhas do cartão "Ferramentas do escritório" no Meu Perfil, ou null para quem não
 * é o dono do escritório da sessão (o cartão não aparece). Molde: `backup_da_pessoa()` (BackupNoPerfilExtension).
 * Só o Meu Perfil chama: nenhuma consulta a mais nas outras páginas.
 */
final class FerramentasNoPerfilExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly ListarFerramentasDoEscritorioUseCase $listar,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('ferramentas_do_escritorio', $this->ferramentas(...))];
    }

    /** @return list<FerramentaDoCartaoOutput>|null */
    public function ferramentas(): ?array
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || null === $tenant) {
            return null;
        }

        return $this->listar->executar($usuario, $tenant);
    }
}
