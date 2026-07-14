<?php

declare(strict_types=1);

namespace App\Tests\Sync\Support;

use App\Entity\Tenant\Tenant;
use App\Sync\DTO\DriveDoTenant;
use App\Sync\Service\GoogleDriveClientFactoryInterface;

/**
 * Fábrica de teste: devolve sempre o mesmo {@see FakeGoogleDriveClient} + a raiz canned, para
 * qualquer tenant. Substitui a fábrica real no container (que consultaria a TenantDriveConexao).
 */
final class FakeGoogleDriveClientFactory implements GoogleDriveClientFactoryInterface
{
    public function __construct(
        private readonly FakeGoogleDriveClient $client,
        private readonly string $rootFolderId = 'ROOT',
    ) {
    }

    public function paraTenant(Tenant $tenant): DriveDoTenant
    {
        return new DriveDoTenant($this->client, $this->rootFolderId);
    }
}
