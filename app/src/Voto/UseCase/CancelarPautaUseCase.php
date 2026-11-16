<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\TransacaoDoVoto;

/**
 * A comissão cancela a pauta (irreversível), com motivo de 5 ou mais caracteres: as cédulas ficam lacradas e sem apuração. Antes de abrir, equivale a retirar de pauta. A pauta é relida TRAVADA (mesma trava do voto, do encerramento e da abertura): nenhum voto cruza com a
 * mudança. Assembleia ou pauta de outro escritório dá AssembleiaNaoEncontradaException (404).
 */
final class CancelarPautaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId, string $motivo, string $por, ?\DateTimeImmutable $agora = null): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $this->transacao->executar(function () use ($assembleia, $pautaId, $motivo, $por, $agora): void {
            $pauta = $this->pautas->buscarTravadaDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
            $pauta->cancelar($motivo, $por, $agora);
        });
    }
}
