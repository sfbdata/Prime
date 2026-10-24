<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;

/**
 * Os lançamentos da pasta relidos do banco sob a trava da pasta, para congelar um êxito ou
 * sucumbência sobre a base de AGORA (spec pasta-exito-sucumbencia, decisão 3).
 *
 * Interface para os UseCases que congelam serem testados sem banco; a implementação é o
 * `PastaPagamentoRepository` (única, o autowiring liga sozinho).
 */
interface LancamentosDaPastaSobTrava
{
    /**
     * Exige transação aberta.
     *
     * @return list<PastaPagamento>
     */
    public function travarPastaELerLancamentos(Pasta $pasta): array;
}
