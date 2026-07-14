<?php

declare(strict_types=1);

namespace App\Sync\DTO;

use App\Sync\Service\GoogleDriveClientInterface;

/**
 * O que a {@see \App\Sync\Service\GoogleDriveClientFactory} entrega para um tenant conectado:
 * o client já autenticado com as credenciais DAQUELE escritório e o id da pasta-raiz do acervo
 * dele no Drive (a "raiz" da reconciliação, antes vinda do env global).
 */
final readonly class DriveDoTenant
{
    public function __construct(
        public GoogleDriveClientInterface $client,
        public string $rootFolderId,
    ) {
    }
}
