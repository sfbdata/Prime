<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;

/**
 * Quem vê as abas de gestão da tela /ponto (spec `docs/specs/ponto-desenho-0811.md`, P58: "não"; quem gere o ponto
 * pelo perfil continua vendo, com o primário e o Master). Cada aba segue a permissão que as telas de hoje já pedem
 * para o que ela mostra, para a aba nova não abrir nada que a tela antiga fechava:
 *
 * - Equipe (E5): `admin.users.manage`, a da ficha do colaborador (justificativas, jornada, folha de outra pessoa);
 * - Configurações (E6 em diante): `admin.ponto.manage` (sedes) ou `admin.tenant.settings.manage` (feriados); cada
 *   seção da aba confere a sua.
 *
 * `canAdminister` já deixa passar o Master e o primário ativo, e só lê o perfil de vínculo ativo.
 */
final class GestaoDoPonto
{
    public const EQUIPE = 'admin.users.manage';
    public const SEDES = 'admin.ponto.manage';
    public const FERIADOS = 'admin.tenant.settings.manage';

    public function __construct(
        private readonly PermissionChecker $permissoes,
    ) {
    }

    public function veEquipe(User $quem, Tenant $escritorio): bool
    {
        return $this->permissoes->canAdminister($quem, $escritorio, self::EQUIPE);
    }

    public function veConfiguracoes(User $quem, Tenant $escritorio): bool
    {
        return $this->permissoes->canAdminister($quem, $escritorio, self::SEDES)
            || $this->permissoes->canAdminister($quem, $escritorio, self::FERIADOS);
    }
}
