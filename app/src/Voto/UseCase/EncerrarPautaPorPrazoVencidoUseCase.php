<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\Entity\Assembleia;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\TransacaoDoVoto;

/**
 * Prazo vencido encerra a pauta sozinha, com a hora do prazo e o motivo "Prazo definido pela mesa" (spec
 * voto-assembleia-condominio, seção 1.7). Não há relógio no servidor: quem LÊ a assembleia (painel, acompanhar,
 * assistir, resultados, ata) chama este caso de uso antes de mostrar, e o encerramento é gravado na primeira leitura
 * depois do prazo. O voto já é recusado depois do prazo pela própria pauta (`aceitaVotoEm`).
 *
 * Recebe a assembleia já carregada pelo caso de uso de leitura, que fez a guarda de posse.
 */
final class EncerrarPautaPorPrazoVencidoUseCase
{
    public function __construct(
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    /** Devolve quantas pautas foram encerradas agora. */
    public function executar(Assembleia $assembleia, ?\DateTimeImmutable $agora = null): int
    {
        $agora ??= new \DateTimeImmutable();
        $vencidas = array_filter(
            $assembleia->getPautas()->toArray(),
            static fn (PautaDaAssembleia $pauta): bool => $pauta->prazoVencidoEm($agora),
        );
        if ([] === $vencidas) {
            return 0;
        }

        return $this->transacao->executar(function () use ($assembleia, $vencidas, $agora): int {
            $encerradas = 0;
            foreach ($vencidas as $pauta) {
                $travada = $this->pautas->buscarTravadaDaAssembleia((int) $pauta->getId(), $assembleia);
                $encerradas += true === $travada?->encerrarSePrazoVenceu($agora) ? 1 : 0;
            }

            return $encerradas;
        });
    }
}
