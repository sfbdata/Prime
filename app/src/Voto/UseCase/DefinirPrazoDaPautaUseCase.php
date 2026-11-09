<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\TransacaoDoVoto;

/**
 * Pauta aberta: "Definir prazo" (agora + minutos) ou "Sem prazo" (minutos null, retira o prazo). Se o prazo antigo
 * já venceu, vale o encerramento pelo prazo (nenhum voto foi aceito depois dele): o encerramento é gravado (a
 * transação confirma) e só depois a mesa recebe a recusa.
 */
final class DefinirPrazoDaPautaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId, ?int $minutos, ?\DateTimeImmutable $agora = null): void
    {
        $agora ??= new \DateTimeImmutable();
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $prazo = AbrirPautaUseCase::prazo($minutos, $agora);
        $encerradaPeloPrazo = $this->transacao->executar(function () use ($assembleia, $pautaId, $prazo, $agora): bool {
            $pauta = $this->pautas->buscarTravadaDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
            if ($pauta->encerrarSePrazoVenceu($agora)) {
                return true;
            }
            $pauta->definirPrazo($prazo, $agora);

            return false;
        });
        if ($encerradaPeloPrazo) {
            throw new OperacaoInvalidaNaAssembleiaException('O prazo desta votação já terminou e ela foi encerrada.');
        }
    }
}
