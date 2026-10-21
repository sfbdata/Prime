<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;

/**
 * O que a pessoa vê das pastas na Agenda (spec agenda-prazos-permissao). Duas regras separadas:
 * os prazos e audiências das pastas (grade, lembretes, push e "Avisar no Chat" do prazo) seguem a
 * permissão de perfil "Ver prazos na agenda"; ligar e mostrar a pasta de um compromisso comum
 * segue o módulo Pastas. O cadeado da pasta vale por cima das duas, nos casos de uso.
 */
final class PermissoesDaAgenda
{
    public const VER_PRAZOS = 'resources.agenda.ver_prazos';

    public function __construct(private readonly PermissionChecker $permissoes)
    {
    }

    public function verPrazos(User $pessoa, Tenant $tenant): bool
    {
        return $this->permissoes->hasPermission($pessoa, $tenant, self::VER_PRAZOS);
    }

    public function verPastas(User $pessoa, Tenant $tenant): bool
    {
        return $this->permissoes->canAccessModule($pessoa, $tenant, 'pastas');
    }
}
