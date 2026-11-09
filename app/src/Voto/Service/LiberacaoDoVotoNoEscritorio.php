<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Master\Service\RecursosDoSistema;
use App\Service\Tenant\TenantContext;
use App\Voto\Repository\LiberacaoDoVotoRepository;

/**
 * A trava da liberação por escritório (spec docs/specs/bluejus-voto.md, D5 e D7, fase A1b): o Voto só abre no escritório
 * que o Master liberou na aba "Voto" do painel. Soma-se às outras duas travas (a chave `voto` de Telas e recursos e a
 * permissão `modules.voto.view`); não substitui nenhuma.
 *
 * O Master passa sempre, como na chave do catálogo: ele confere a tela antes de liberar. Os demais precisam da linha do
 * escritório da sessão com `liberado`; sem escritório na sessão ou sem linha, não está liberado.
 */
final class LiberacaoDoVotoNoEscritorio
{
    public function __construct(
        private readonly RecursosDoSistema $recursos,
        private readonly TenantContext $tenantContext,
        private readonly LiberacaoDoVotoRepository $liberacoes,
    ) {
    }

    public function liberadoParaOUsuarioAtual(): bool
    {
        if ($this->recursos->usuarioEhMaster()) {
            return true;
        }
        $tenant = $this->tenantContext->getCurrentTenant();

        return $tenant !== null && $this->liberacoes->findDoTenant($tenant)?->isLiberado() === true;
    }
}
