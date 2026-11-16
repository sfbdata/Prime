<?php

declare(strict_types=1);

namespace App\Conector\DTO;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;

/**
 * Quem age e onde, pelo conector de I.A.: a pessoa, o escritório e o vínculo do token, já conferidos pelo
 * PortaoDoConector. Toda ferramenta passa a pessoa e o escritório daqui ao UseCase (spec mcp-remoto-oauth 4.2), nunca
 * de sessão, Security ou Request.
 */
final class ContextoDoConector
{
    public function __construct(
        public readonly User $pessoa,
        public readonly Tenant $escritorio,
        public readonly UserTenant $vinculo,
    ) {
    }
}
