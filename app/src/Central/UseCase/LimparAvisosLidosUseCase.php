<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\SemAcessoACentralException;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisosDaCentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\NotificacaoService;

/**
 * ⋮ "Limpar notificações lidas" (F3, L6283): apaga as notificações JÁ LIDAS que aparecem na Caixa da pessoa, neste
 * escritório, pelo mesmo serviço do sino. Gestão e avisos da Central ficam (não são da Caixa). A tela confirma antes.
 */
final class LimparAvisosLidosUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly AvisosDaCentral $avisos,
        private readonly NotificacaoService $notificacoes,
    ) {
    }

    /** @throws SemAcessoACentralException */
    public function executar(User $eu, ?Tenant $tenant): int
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);

        return $this->notificacoes->excluir($eu, $tenant, $this->avisos->idsLidos($eu, $tenant));
    }
}
