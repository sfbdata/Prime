<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Service\HierarquiaDePerfis;

/**
 * Quem pode mudar as caixas da esteira (D2 da spec minhas-metas-esteira-2210, decidida pelo dono em 22/10):
 * o primário do escritório e quem administra perfis. É a mesma régua da lista de Perfis
 * (`HierarquiaDePerfis::podeVerALista`: primário, Master ou `admin.roles.manage`), porque a esteira é
 * configuração do escritório inteiro.
 *
 * Não é `final` de propósito: os testes de UseCase o substituem (a `HierarquiaDePerfis` é final).
 */
class QuemConfiguraACaixa
{
    public function __construct(
        private readonly HierarquiaDePerfis $hierarquia,
    ) {
    }

    public function pode(User $usuario, Tenant $escritorio): bool
    {
        return $this->hierarquia->podeVerALista($usuario, $escritorio);
    }
}
