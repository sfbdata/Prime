<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;

/**
 * A chave "Sugestões a partir dos meus despachos" do Meu Perfil (D4 de Minhas Metas): por pessoa e escritório,
 * ligada por padrão (o que não está gravado vale ligado). Desligada, nada é sugerido NEM gravado.
 */
final class PreferenciaDoAprendizado
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    public function ligado(Tenant $escritorio, User $usuario): bool
    {
        $chave = CatalogoDePreferenciasDoPerfil::SUGESTOES_DESPACHOS;

        return CatalogoDePreferenciasDoPerfil::efetivas($this->preferencias->valoresDoUsuario($escritorio, $usuario, [$chave]))[$chave];
    }
}
