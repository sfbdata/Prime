<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Entity\Tenant\Tenant;
use App\Sync\DTO\DriveDoTenant;
use App\Sync\Exception\TenantSemDriveException;
use App\Sync\Repository\TenantDriveConexaoRepository;

/**
 * Constrói o {@see GoogleDriveClient} de um tenant: lê a conexão ativa, decifra o refresh_token e
 * instancia o client com as credenciais globais do app (client_id/secret) + o refresh_token DAQUELE
 * escritório. Devolve também a pasta-raiz do acervo do tenant. Sem conexão ativa → exceção (no-op p/ os chamadores).
 */
final class GoogleDriveClientFactory implements GoogleDriveClientFactoryInterface
{
    public function __construct(
        private readonly TenantDriveConexaoRepository $conexoes,
        private readonly CifradorDeSegredo $cifrador,
        private readonly string $googleDriveOauthClientId,
        private readonly string $googleDriveOauthClientSecret,
    ) {
    }

    public function paraTenant(Tenant $tenant): DriveDoTenant
    {
        $conexao = $this->conexoes->findAtivaDoTenant($tenant);
        if ($conexao === null) {
            throw TenantSemDriveException::paraTenant((int) $tenant->getId());
        }

        $refreshToken = $this->cifrador->decifrar($conexao->getRefreshTokenCifrado());

        $client = new GoogleDriveClient(
            googleDriveCredentials: null,
            googleDriveOauthClientId: $this->googleDriveOauthClientId,
            googleDriveOauthClientSecret: $this->googleDriveOauthClientSecret,
            googleDriveOauthRefreshToken: $refreshToken,
        );

        // estaPronta() garante rootFolderId não-nulo/não-vazio.
        return new DriveDoTenant($client, (string) $conexao->getRootFolderId());
    }
}
