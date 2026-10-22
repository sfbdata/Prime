<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;

/**
 * A trava por meta de quem muda o status dela (passagem, devolução, despacho, concluir e reabrir): dentro da
 * transação, trava a linha da meta até o COMMIT e a relê do banco. Duas mudanças ao mesmo tempo ficam em fila, e a
 * segunda confere o status que a primeira gravou em vez do que tinha em memória.
 */
interface TravaDaMeta
{
    /** Só dentro de uma transação aberta. */
    public function travarERecarregar(Tarefa $meta): void;
}
