<?php

declare(strict_types=1);

namespace App\Backup\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * A regra "backup só para o dono do escritório" (spec backup-e-exportacao-de-dados, seção 2, regra 8; decisão do
 * Samuel, 23/11). Dono = `Tenant::getCriadoPor()`, comparado por id. Escritório sem criador (nulo) não tem dono:
 * ninguém passa. Sem sessão: serve a tela, o UseCase, o download e o worker.
 */
final class DonoDoEscritorio
{
    public function ehDono(User $usuario, Tenant $tenant): bool
    {
        $dono = $tenant->getCriadoPor();

        return null !== $dono
            && null !== $dono->getId()
            && $dono->getId() === $usuario->getId();
    }
}
