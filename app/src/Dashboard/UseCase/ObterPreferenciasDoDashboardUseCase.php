<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\PreferenciasDoDashboardOutput;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * O estilo do Dashboard do usuário LOGADO no escritório da sessão (quem chama passa os dois, tirados
 * da sessão; não existe leitura da preferência de outra pessoa). O que não foi gravado vem do padrão.
 */
final class ObterPreferenciasDoDashboardUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $repository,
    ) {
    }

    public function executar(Tenant $tenant, User $usuario): PreferenciasDoDashboardOutput
    {
        return PreferenciasDoDashboardOutput::deValores(
            $this->repository->valoresDoUsuario($tenant, $usuario, CatalogoDePreferenciasDoDashboard::chaves()),
        );
    }
}
