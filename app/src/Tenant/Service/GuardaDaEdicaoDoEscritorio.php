<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Exception\AcessoAoEscritorioNegado;

/**
 * D1 da spec meu-escritorio.md: salvar dados, enviar e remover a logo só o primário do escritório ou o Master faz. A
 * regra é a do `RegraDosPrimarios::podeGerirPrimarios` (a mesma que protege o perfil de sistema), no servidor.
 */
final class GuardaDaEdicaoDoEscritorio
{
    public function __construct(
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {
    }

    public function pode(User $ator, Tenant $tenant): bool
    {
        return $this->regraDosPrimarios->podeGerirPrimarios($ator, $tenant);
    }

    /** @throws AcessoAoEscritorioNegado */
    public function exigir(User $ator, Tenant $tenant): void
    {
        if (!$this->pode($ator, $tenant)) {
            throw new AcessoAoEscritorioNegado();
        }
    }
}
