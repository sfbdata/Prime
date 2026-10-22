<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;

/**
 * A gravação da passagem de etapa e da devolução numa transação só (spec `minhas-metas-esteira-2210.md`, Fase 3): a
 * meta atual, a meta nova com o número dela no escritório, o registro no histórico e os avisos. Interface própria
 * para os UseCases serem testados sem o EntityManager. A trava da meta ({@see TravaDaMeta}) vem junto.
 */
interface TransacaoDaPassagem extends TravaDaMeta
{
    /**
     * Roda o trabalho numa transação; o `flush` sai no fim. Qualquer falha desfaz tudo.
     *
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(\Closure $trabalho): mixed;

    /** Reserva o número da meta nova na sequência do escritório e a grava (só dentro de `emTransacao`). */
    public function gravarMetaNova(Tarefa $nova): void;
}
