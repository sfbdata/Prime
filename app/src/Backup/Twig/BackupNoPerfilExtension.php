<?php

declare(strict_types=1);

namespace App\Backup\Twig;

use App\Backup\DTO\PedidoDeBackupLinha;
use App\Backup\UseCase\ListarPedidosDeBackupUseCase;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `backup_da_pessoa()`: a lista de pedidos de backup da pessoa logada no escritório atual, para a seção do Meu Perfil.
 * Sem pessoa ou sem escritório (conta do Master sem escritório) devolve nulo e a seção não aparece.
 */
final class BackupNoPerfilExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly ListarPedidosDeBackupUseCase $listar,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('backup_da_pessoa', [$this, 'pedidos'])];
    }

    /** @return list<PedidoDeBackupLinha>|null */
    public function pedidos(): ?array
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || null === $tenant) {
            return null;
        }

        return $this->listar->executar($usuario, $tenant);
    }
}
