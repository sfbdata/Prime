<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Exception\CustoRecusado;
use App\Master\Repository\LancamentoDeCustoRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/** Master exclui um lançamento de custo (spec master-custos D8): exclusão de verdade, com a linha na trilha da plataforma. */
final class ExcluirLancamentoDeCustoUseCase
{
    public function __construct(
        private readonly LancamentoDeCustoRepository $lancamentos,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(int $id): void
    {
        $lancamento = $this->lancamentos->find($id);
        if ($lancamento === null) {
            throw new CustoRecusado('Lançamento não encontrado.');
        }
        $this->escopoDePlataforma->executar(fn () => $this->lancamentos->remover($lancamento, flush: true));
    }
}
