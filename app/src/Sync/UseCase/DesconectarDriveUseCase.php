<?php

declare(strict_types=1);

namespace App\Sync\UseCase;

use App\Entity\Tenant\Tenant;
use App\Sync\Repository\TenantDriveConexaoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Desconecta o Drive do escritório: apaga o token cifrado e a pasta-raiz e desativa a conexão
 * (a linha permanece para histórico de quem/quando). No-op se o tenant não tem conexão.
 */
final class DesconectarDriveUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantDriveConexaoRepository $conexoes,
    ) {
    }

    public function executar(Tenant $tenant): void
    {
        $conexao = $this->conexoes->findDoTenant($tenant);
        if ($conexao === null) {
            return;
        }

        $conexao->desconectar();
        $this->em->flush();
    }
}
