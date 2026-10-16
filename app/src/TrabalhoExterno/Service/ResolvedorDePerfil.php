<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Enum\Perfil;

/**
 * Decide o perfil de quem acessa o Trabalho Externo (spec §2).
 *
 *  - **Master** é quem tem `ROLE_SUPER_ADMIN` (D1: o master global do sistema é reconhecido pelo
 *    super admin). É a única leitura de papel direta deste domínio, e é deliberada: o
 *    `PermissionChecker` também dá bypass ao super admin, mas aqui o papel não é "pode tudo", é
 *    "é a Central". Tem de valer em qualquer escritório, inclusive sem escritório escolhido.
 *  - **Equipe**: quem trabalha no escritório-sede da Central (D2) e tem a permissão do módulo.
 *  - **Solicitante**: quem tem a permissão do módulo em qualquer outro escritório.
 *  - Sem a permissão (ou sem escritório e sem ser Master): sem perfil, e o controller nega.
 */
final class ResolvedorDePerfil
{
    public const MODULO = 'trabalho_externo';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function resolver(User $usuario, ?Tenant $tenant, ConfiguracaoCentral $configuracao): ?Perfil
    {
        if (in_array('ROLE_SUPER_ADMIN', $usuario->getRoles(), true)) {
            return Perfil::Master;
        }
        if ($tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO)) {
            return null;
        }

        $sede = $configuracao->getSede();

        return $sede !== null && $sede->getId() === $tenant->getId() ? Perfil::Equipe : Perfil::Solicitante;
    }
}
