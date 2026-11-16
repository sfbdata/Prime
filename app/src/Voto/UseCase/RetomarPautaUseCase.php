<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\TransacaoDoVoto;

/**
 * A mesa retoma a pauta suspensa: volta a aceitar voto e o prazo anda o tempo que ficou parada. A pauta é relida TRAVADA (mesma trava do voto, do encerramento e da abertura): nenhum voto cruza com a
 * mudança. Assembleia ou pauta de outro escritório dá AssembleiaNaoEncontradaException (404).
 */
final class RetomarPautaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId, string $por, ?\DateTimeImmutable $agora = null): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $this->transacao->executar(function () use ($assembleia, $pautaId, $por, $agora): void {
            $pauta = $this->pautas->buscarTravadaDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
            $pauta->retomar($por, $agora);
        });
    }
}
