<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;

/**
 * Gravar uma meta nova já numerada na sequência do escritório. A implementação é o `GravarMetaNovaUseCase` (a trava da
 * numeração e o flush numa transação só); a interface existe para quem grava meta nova por ele (o
 * `DuplicarMetaUseCase`) ser testado sem banco e sem mockar o EntityManager.
 */
interface GravacaoDeMetaNova
{
    /**
     * @param bool $herdarPrioridadeDaPasta false quando a prioridade da meta já é a decidida (a cópia do "Duplicar"
     *                                       leva a da original, inclusive Normal)
     */
    public function executar(Tarefa $tarefa, bool $herdarPrioridadeDaPasta = true): void;
}
