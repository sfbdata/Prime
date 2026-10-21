<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;

/**
 * Provisória até o #75 (`user_tenant.controladoria`) entrar no master: nenhum escritório tem
 * Controladoria. O modal mostra "Nenhum usuário com perfil de Controladoria cadastrado." e o envio
 * avisa o primário no sino. Trocar: implementar a interface lendo a coluna do #75 e apagar esta.
 */
final class ControladoriaDoEscritorioProvisoria implements ControladoriaDoEscritorioInterface
{
    public function pessoas(Tenant $tenant): array
    {
        return [];
    }
}
