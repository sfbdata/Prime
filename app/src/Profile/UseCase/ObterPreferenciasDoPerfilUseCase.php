<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;

/**
 * Preferências do card "Preferências" do Meu Perfil, já com o padrão (ligado) no que faltar.
 * Sem escritório na sessão não há onde gravar: devolve o padrão.
 */
final class ObterPreferenciasDoPerfilUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    /** @return array<string, bool> */
    public function executar(?Tenant $tenant, User $usuario): array
    {
        $gravados = $tenant === null
            ? []
            : $this->preferencias->valoresDoUsuario($tenant, $usuario, CatalogoDePreferenciasDoPerfil::chaves());

        return CatalogoDePreferenciasDoPerfil::efetivas($gravados);
    }
}
