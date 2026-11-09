<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\TransacaoDoVoto;

/**
 * A mesa encerra a pauta aberta com o "Motivo do encerramento antecipado" (vazio vira "Determinação da mesa"). A
 * pauta é relida TRAVADA: o voto que está sendo gravado termina antes, e nenhum entra depois. Sem guardiões, o
 * resultado sai no encerramento.
 */
final class EncerrarPautaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId, string $nome, string $motivo, ?\DateTimeImmutable $agora = null): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $this->transacao->executar(function () use ($assembleia, $pautaId, $nome, $motivo, $agora): void {
            $pauta = $this->pautas->buscarTravadaDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
            $pauta->encerrar($nome, $motivo, $agora);
        });
    }
}
