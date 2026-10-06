<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\PreferenciasDoDashboardOutput;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * "Restaurar padrão" do menu ⋮: volta SÓ o usuário logado, neste escritório, ao estilo padrão
 * (desenho: "volta só o usuário atual ao padrão"). Apaga as linhas em vez de gravar o padrão —
 * assim, se o padrão mudar um dia, quem restaurou acompanha.
 */
final class RestaurarPreferenciasDoDashboardUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $repository,
    ) {
    }

    public function executar(Tenant $tenant, User $usuario): PreferenciasDoDashboardOutput
    {
        $this->repository->apagarDoUsuario($tenant, $usuario, CatalogoDePreferenciasDoDashboard::chaves());

        return PreferenciasDoDashboardOutput::padrao();
    }
}
