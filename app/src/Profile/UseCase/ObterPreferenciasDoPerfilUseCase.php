<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Profile\Service\AssinaturaDoResumoDiario;

/**
 * Preferências do card "Preferências" do Meu Perfil, já com o padrão (ligado) no que faltar.
 * Sem escritório na sessão não há onde gravar: devolve o padrão.
 */
final class ObterPreferenciasDoPerfilUseCase
{
    public function __construct(
        private readonly PreferenciaDoUsuarioRepository $preferencias,
        private readonly ?AssinaturaDoResumoDiario $resumo = null,
    ) {
    }

    /**
     * As do card "Preferências" da tela: o Resumo diário vem da fonte única (com o descadastro do rodapé). Só a tela do
     * Meu Perfil pergunta isso; o resto (sons, sininho, a cada request) usa `executar`, sem a consulta a mais.
     *
     * @return array<string, bool>
     */
    public function paraATela(?Tenant $tenant, User $usuario): array
    {
        $efetivas = $this->executar($tenant, $usuario);
        if ($tenant !== null && $this->resumo !== null) {
            $efetivas[CatalogoDePreferenciasDoPerfil::RESUMO_EMAIL] = $this->resumo->ligado($tenant, $usuario);
        }

        return $efetivas;
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
