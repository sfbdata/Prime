<?php

declare(strict_types=1);

namespace App\Sync\UseCase;

use App\Entity\Tenant\Tenant;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Repository\TenantDriveConexaoRepository;
use App\Sync\Service\ExtratorDeFolderId;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Define a pasta-raiz do acervo do escritório no Drive (o que o admin colou) e ATIVA a conexão.
 * Pré-condição: a conta já conectada (refresh_token presente). Multi-tenant: só a conexão do tenant.
 */
final class DefinirRootFolderDriveUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantDriveConexaoRepository $conexoes,
        private readonly ExtratorDeFolderId $extrator,
    ) {
    }

    public function executar(Tenant $tenant, string $rootFolderBruto): TenantDriveConexao
    {
        $conexao = $this->conexoes->findDoTenant($tenant);
        if ($conexao === null || $conexao->getRefreshTokenCifrado() === '') {
            throw new \DomainException('Conecte a conta do Google primeiro, depois informe a pasta-raiz.');
        }

        // Lança \InvalidArgumentException se a entrada não tiver um id de pasta reconhecível.
        $folderId = $this->extrator->extrair($rootFolderBruto);

        $conexao->definirRootFolder($folderId);

        $this->em->flush();

        return $conexao;
    }
}
