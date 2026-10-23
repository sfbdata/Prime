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
    public function executar(Tarefa $tarefa): void;
}
