<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\LancarCustoInput;
use App\Master\Entity\LancamentoDeCusto;
use App\Master\Repository\LancamentoDeCustoRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Master lança um custo do mês (spec docs/specs/master-custos.md). Auditável, fora do escritório da sessão: o custo é
 * da plataforma, então a linha da trilha fica sem conta (EscopoDeAuditoriaDePlataforma).
 */
final class LancarCustoUseCase
{
    public function __construct(
        private readonly LancamentoDeCustoRepository $lancamentos,
        private readonly ClockInterface $clock,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(LancarCustoInput $input): LancamentoDeCusto
    {
        $lancamento = new LancamentoDeCusto(
            $input->mes,
            $input->categoria,
            $input->valorCentavos,
            $input->origem,
            $input->fornecedor,
            $input->observacao,
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $input->ator,
        );
        $this->escopoDePlataforma->executar(fn () => $this->lancamentos->salvar($lancamento, flush: true));

        return $lancamento;
    }
}
