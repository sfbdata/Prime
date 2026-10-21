<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;

/**
 * Liga ou desliga UMA preferência do Meu Perfil (Notificações no sistema, Sons de alerta, Resumo
 * diário por e-mail). Quem: o usuário logado, para ele mesmo, no escritório da sessão. Chave fora
 * do catálogo é recusada antes de chegar ao banco.
 */
final class SalvarPreferenciaDoPerfilUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    /** @throws \InvalidArgumentException chave fora do catálogo */
    public function executar(Tenant $tenant, User $usuario, string $chave, bool $ligado): void
    {
        if (!CatalogoDePreferenciasDoPerfil::existe($chave)) {
            throw new \InvalidArgumentException('Preferência desconhecida.');
        }

        $this->preferencias->gravar($tenant, $usuario, $chave, $ligado);
    }
}
