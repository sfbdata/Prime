<?php

declare(strict_types=1);

namespace App\Inteligencia\Contract;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Serializa os pedidos da aba "Perguntar" de UMA pessoa em UMA pasta (spec inteligencia-drawer-da-pasta,
 * F3): sem ela, dois POSTs simultâneos passam juntos pela checagem "já há pergunta em andamento?", criam
 * duas perguntas e gastam o limitador duas vezes. A implementação abre a transação, pega a trava e roda
 * `$trabalho` dentro dela; a trava cai no commit (ou no rollback, se `$trabalho` lançar).
 */
interface TravaDaConversa
{
    /**
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    public function emTransacaoTravada(Tenant $tenant, int $pastaId, User $autor, callable $trabalho): mixed;
}
