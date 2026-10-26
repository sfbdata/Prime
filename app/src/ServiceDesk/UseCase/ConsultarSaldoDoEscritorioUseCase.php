<?php

declare(strict_types=1);

namespace App\ServiceDesk\UseCase;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\DTO\MovimentoDoExtratoOutput;
use App\ServiceDesk\DTO\SaldoDoEscritorioOutput;
use App\ServiceDesk\Repository\MovimentoDeSaldoRepository;
use App\ServiceDesk\Repository\SaldoDoEscritorioRepository;

/** Saldo, valor aguardando confirmação e extrato (30 últimos) do cartão "Saldo para atendimento técnico". */
final class ConsultarSaldoDoEscritorioUseCase
{
    private const LIMITE_DO_EXTRATO = 30;

    public function __construct(
        private readonly SaldoDoEscritorioRepository $saldos,
        private readonly MovimentoDeSaldoRepository $movimentos,
    ) {
    }

    public function executar(Tenant $tenant): SaldoDoEscritorioOutput
    {
        $saldo = $this->saldos->doEscritorio($tenant);
        $extrato = array_map(
            MovimentoDoExtratoOutput::fromEntity(...),
            $this->movimentos->recentesDoEscritorio($tenant, self::LIMITE_DO_EXTRATO),
        );

        return new SaldoDoEscritorioOutput(
            $saldo->getSaldoCentavos(),
            $this->movimentos->somaPendentesDoEscritorio($tenant),
            $saldo->permiteAbrirChamado(),
            array_values($extrato),
        );
    }
}
