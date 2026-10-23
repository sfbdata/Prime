<?php

declare(strict_types=1);

namespace App\Shared\Scheduler\UseCase;

use App\Entity\Auth\User;
use App\Shared\Scheduler\QuemVeAvisoDoAgendador;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Dispensar o aviso de falha das tarefas agendadas (spec scheduler-tarefas-agendadas, "Aviso de falha").
 *
 * Quem: o Master ou a pessoa de AGENDADOR_ALERTA_EMAIL ({@see QuemVeAvisoDoAgendador}), pelo botão do aviso.
 * O quê: tirar o aviso da tela de todos os que o veem. Todas as falhas pendentes são marcadas de uma vez,
 * com quem e quando; as falhas continuam na Central do Master. Sem pendente, não muda nada.
 */
final class DispensarFalhasAgendadasUseCase
{
    public function __construct(
        private readonly FalhaAgendadaRepository $falhas,
        private readonly QuemVeAvisoDoAgendador $quemVe,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** Devolve quantas falhas foram dispensadas. */
    public function executar(User $quem): int
    {
        if (!$this->quemVe->podeVer($quem)) {
            throw new AccessDeniedException('Só o Master ou o endereço de alerta do agendador dispensa o aviso.');
        }

        return $this->falhas->dispensarPendentes($quem, $this->relogio->now());
    }
}
