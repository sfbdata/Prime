<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;

/** Monta o {@see Contexto} da requisição: usuário logado, escritório da sessão, perfil e configuração. */
final class FabricaDeContexto
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResolvedorDePerfil $resolvedor,
        private readonly ConfiguracaoCentralRepository $configuracoes,
    ) {
    }

    /** Null quando o usuário não tem perfil no Trabalho Externo (o controller nega o acesso). */
    public function para(mixed $usuario): ?Contexto
    {
        if (!$usuario instanceof User) {
            return null;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $configuracao = $this->configuracoes->atual();
        $perfil = $this->resolvedor->resolver($usuario, $tenant, $configuracao);

        return $perfil !== null ? new Contexto($usuario, $tenant, $perfil, $configuracao) : null;
    }
}
