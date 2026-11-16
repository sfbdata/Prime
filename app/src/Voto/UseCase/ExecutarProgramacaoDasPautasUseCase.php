<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\TransacaoDoVoto;

/**
 * Horário programado (desenho 18, "Programados no horário"): a pauta abre e encerra sozinha. Roda a cada minuto pelo
 * agendador do servidor (`voto:executar-programacao`, {@see \App\Shared\Scheduler\AgendaDeTarefas}) e percorre os
 * escritórios todos: é tarefa do sistema, sem usuário nem escritório na sessão.
 *
 *  - abrir: assembleia publicada e não arquivada, pauta aguardando, não retirada, com a abertura chegada e o
 *    encerramento por vir. Abre em 1ª convocação, com o prazo no horário de encerrar e "Programado" como quem abriu;
 *  - encerrar: pauta aberta com o prazo vencido e não suspensa (suspensa congela o prazo), com o motivo
 *    "Encerramento programado". Pauta cancelada já está encerrada e nunca volta.
 *
 * Cada pauta é relida TRAVADA (a mesma trava do voto e da mesa) e conferida de novo: a mesa pode ter suspendido,
 * cancelado ou retirado entre a consulta e a trava. Recusa de negócio de uma pauta não derruba as demais.
 */
final class ExecutarProgramacaoDasPautasUseCase
{
    public function __construct(
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    /** @return array{abertas: int, encerradas: int} quantas pautas abriram e quantas encerraram agora */
    public function executar(?\DateTimeImmutable $agora = null): array
    {
        $agora ??= new \DateTimeImmutable();
        $resumo = ['abertas' => 0, 'encerradas' => 0];
        foreach ($this->pautas->aProgramar($agora) as $candidata) {
            $acao = $this->aplicar($candidata, $agora);
            if (null !== $acao) {
                ++$resumo[$acao];
            }
        }

        return $resumo;
    }

    /** @return 'abertas'|'encerradas'|null */
    private function aplicar(PautaDaAssembleia $candidata, \DateTimeImmutable $agora): ?string
    {
        try {
            return $this->transacao->executar(function () use ($candidata, $agora): ?string {
                $pauta = $this->pautas->buscarTravadaDaAssembleia((int) $candidata->getId(), $candidata->getAssembleia());
                if (null === $pauta) {
                    return null;
                }
                if ($pauta->deveAbrirPorProgramacaoEm($agora)) {
                    $pauta->abrirPorProgramacao($agora);

                    return 'abertas';
                }

                return $pauta->encerrarSePrazoVenceu($agora) ? 'encerradas' : null;
            });
        } catch (OperacaoInvalidaNaAssembleiaException) {
            // A mesa mudou a pauta entre a consulta e a trava: nada a fazer agora.
            return null;
        }
    }
}
