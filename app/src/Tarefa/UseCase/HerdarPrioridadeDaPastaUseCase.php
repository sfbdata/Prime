<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Tarefa\Repository\TarefaRepository;

/**
 * Dá às metas que já existiam a prioridade herdada da pasta (backfill do
 * `app:tarefa:herdar-prioridade-da-pasta`, que o dono roda uma vez depois da publicação). Pasta `urgente`
 * vira meta Urgente, `prioridade` vira Alta, o resto fica Normal: o mesmo de-para da meta nova
 * (`PrioridadeDaMeta::daPasta`).
 *
 * Roda sem sessão: o escritório é sempre explícito (o id vem do comando ou da lista dos que têm meta a
 * herdar) e cada escritório é gravado à parte.
 *
 * Idempotente: só toca meta ainda `normal`, então a segunda rodada não muda nada.
 */
final class HerdarPrioridadeDaPastaUseCase
{
    public function __construct(
        private readonly TarefaRepository $tarefas,
    ) {
    }

    /**
     * O que seria herdado, sem gravar nada.
     *
     * @return array<int, int> tenant_id => metas a herdar
     */
    public function simular(?int $tenantId = null): array
    {
        return $this->tarefas->contarHerancaDePrioridadePendente($tenantId);
    }

    /**
     * @return array<int, int> tenant_id => metas atualizadas agora
     */
    public function executar(?int $tenantId = null): array
    {
        $atualizadas = [];
        foreach (array_keys($this->tarefas->contarHerancaDePrioridadePendente($tenantId)) as $escritorio) {
            $atualizadas[$escritorio] = $this->tarefas->herdarPrioridadeDaPasta($escritorio);
        }

        return $atualizadas;
    }
}
