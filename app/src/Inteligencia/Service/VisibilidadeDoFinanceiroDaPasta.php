<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\PermissionChecker;

/**
 * Pode esta pessoa ver o financeiro desta pasta? É o que decide se a seção `<financeiro>` entra no
 * contexto de um agente (spec fatia 2 §2). A decisão é tomada NA SOLICITAÇÃO (há sessão e usuário)
 * e gravada em `contexto_resumo.financeiro`; o worker só obedece.
 *
 * Regra de hoje — a mesma da aba Financeiro da tela e do "Imprimir resumo"
 * (`PastaResumoController::podeVerFinanceiro`): quem passou pelo guarda de leitura da pasta vê o
 * financeiro, porque não existe permissão própria com tela (`modules.financeiro.view` é "futuro"
 * no catálogo). Quando ela ganhar tela, o aperto entra AQUI, num lugar só.
 *
 * Aba Financeiro oculta para a pessoa nesta pasta (spec pasta-ocultar-aba §4) = não vê o financeiro:
 * pendência, timeline, impressão, extrato, menções e análise seguem esta regra sem cópia.
 */
// Não-final (como os repositórios): o teste do UseCase precisa de um dublê que diga "não" sem
// mexer no guarda de leitura da pasta, que hoje usa a mesma pergunta ao PermissionChecker.
class VisibilidadeDoFinanceiroDaPasta
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly VisibilidadeDasAbasDaPasta $visibilidadeDasAbas,
    ) {
    }

    public function podeVer(User $user, Tenant $tenant, Pasta $pasta): bool
    {
        return $this->permissionChecker->canAccessResource($user, $tenant, 'pasta', (int) $pasta->getId(), 'view')
            && !$this->visibilidadeDasAbas->ocultaPara($pasta, AbaDaPasta::Financeiro, $user);
    }
}
