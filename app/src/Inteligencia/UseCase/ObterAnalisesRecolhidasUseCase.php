<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Preferencia\CatalogoDePreferenciasDaInteligencia;

/**
 * Os ids das análises do Push que o usuário LOGADO recolheu, neste escritório (IA-010). Quem
 * chama passa usuário e escritório da sessão; não existe leitura da preferência de outra pessoa.
 * Sem linha gravada → lista vazia (tudo aberto, o padrão do desenho).
 */
final class ObterAnalisesRecolhidasUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
    ) {
    }

    /** @return list<int> */
    public function executar(Tenant $tenant, User $user): array
    {
        $chave = CatalogoDePreferenciasDaInteligencia::PUSH_RECOLHIDAS;

        return CatalogoDePreferenciasDaInteligencia::recolhidasDe(
            $this->preferencias->valoresDoUsuario($tenant, $user, [$chave])[$chave] ?? [],
        );
    }
}
