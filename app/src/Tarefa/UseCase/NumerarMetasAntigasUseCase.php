<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Tarefa\Service\NumeracaoDeMetas;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dá número às metas criadas antes da numeração por escritório existir (backfill do
 * `app:tarefa:numerar-metas`, que o dono roda uma vez depois da publicação).
 *
 * Roda sem sessão: o escritório é sempre explícito (o id vem do comando ou da lista dos que têm meta
 * sem número), e cada escritório é numerado numa transação própria, sob a mesma trava da criação. Uma
 * meta criada pela tela durante a numeração espera a trava e pega o número seguinte.
 *
 * Idempotente: só toca metas com número nulo, então a segunda rodada não muda nada.
 */
final class NumerarMetasAntigasUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NumeracaoDeMetas $numeracao,
    ) {
    }

    /**
     * O que seria numerado, sem gravar nada.
     *
     * @return array<int, int> tenant_id => metas sem número
     */
    public function simular(?int $tenantId = null): array
    {
        return $this->numeracao->pendentesPorEscritorio($tenantId);
    }

    /**
     * @return array<int, int> tenant_id => metas numeradas agora
     */
    public function executar(?int $tenantId = null): array
    {
        $numeradas = [];
        foreach (array_keys($this->numeracao->pendentesPorEscritorio($tenantId)) as $escritorio) {
            $numeradas[$escritorio] = $this->em->wrapInTransaction(
                fn (): int => $this->numeracao->numerarPendentes($escritorio)
            );
        }

        return $numeradas;
    }
}
