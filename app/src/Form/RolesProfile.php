<?php

namespace App\Form;

/**
 * Catálogo de roles técnicas de autenticação (Symfony Security).
 * Não representa mais cargos/perfis de tenant — esses são gerenciados via TenantRole.
 */
class RolesProfile
{
    public const ROLES = [
        'Comercial'   => 'ROLE_COMERCIAL',
        'Closer'      => 'ROLE_CLOSER',
        'Financeiro'  => 'ROLE_FINANCEIRO',
        'Operacional' => 'ROLE_OPERACIONAL',
    ];
}
