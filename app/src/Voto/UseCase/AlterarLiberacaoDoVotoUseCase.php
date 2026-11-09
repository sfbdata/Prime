<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Exception\EscritorioNaoEncontradoException;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\Service\GuardaDoMasterDoVoto;

/**
 * O Master libera (ou bloqueia) o BlueJus Voto para UM escritório (spec docs/specs/bluejus-voto.md, D5 e D7, fase A1b).
 * Liberar não liga o Voto sozinho: a chave `voto` de Telas e recursos também precisa estar ligada, e quem usa ainda
 * precisa da permissão `modules.voto.view`. Todo escritório liberado começa em modo demonstração.
 *
 * Escritório inexistente, suspenso ou excluído: {@see EscritorioNaoEncontradoException} (404). Sem linha de liberação,
 * ela nasce aqui.
 */
final class AlterarLiberacaoDoVotoUseCase
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly LiberacaoDoVotoRepository $liberacoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(int $tenantId, bool $liberado, User $master): void
    {
        GuardaDoMasterDoVoto::exigir($master);
        $tenant = $this->escritorioAtivo($tenantId);
        $liberacao = $this->liberacoes->findDoTenantParaOMaster($tenant) ?? new LiberacaoDoVoto($tenant);

        if ($liberado) {
            $liberacao->liberar();
        } else {
            $liberacao->bloquear();
        }

        // A auditoria vai para o escritório alterado, não para o da sessão do Master.
        $this->escopoDePlataforma->executarComoConta(
            (int) $tenant->getId(),
            fn () => $this->liberacoes->salvar($liberacao, flush: true),
        );
    }

    private function escritorioAtivo(int $tenantId): Tenant
    {
        $tenant = $tenantId > 0 ? $this->tenants->find($tenantId) : null;
        if (!$tenant instanceof Tenant || $tenant->isActive() !== true || $tenant->getExcluidoEm() !== null) {
            throw new EscritorioNaoEncontradoException($tenantId);
        }

        return $tenant;
    }
}
