<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\TransacaoDoVoto;

/**
 * Arquiva a assembleia publicada, com motivo de 5 ou mais caracteres, só sem pauta aberta (ver Assembleia::arquivar()).
 * Todas as pautas são relidas TRAVADAS antes da conferência: uma abertura que está acontecendo agora termina primeiro
 * e a assembleia não é arquivada com votação aberta.
 */
final class ArquivarAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, string $motivo, string $por, ?\DateTimeImmutable $agora = null): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $this->transacao->executar(function () use ($assembleia, $motivo, $por, $agora): void {
            foreach ($assembleia->getPautas() as $pauta) {
                $this->pautas->buscarTravadaDaAssembleia((int) $pauta->getId(), $assembleia);
            }
            $assembleia->arquivar($motivo, $por, $agora);
        });
    }
}
